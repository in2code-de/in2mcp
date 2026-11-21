<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\AccessScope;
use In2code\In2mcp\Domain\Model\AuthorizationCode;
use In2code\In2mcp\Domain\Model\AuthorizationRequest;
use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Exception\OAuthException;
use In2code\In2mcp\Exception\OAuthRedirectException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Random;

/**
 * The authorization code flow with PKCE (OAuth 2.1): the request of the client waits in the cache until the user
 * decides on the consent page, the code that follows is valid once and for a short time only.
 */
readonly class AuthorizationService
{
    public const CACHE_IDENTIFIER = 'in2mcp_oauth';
    public const REQUEST_LIFETIME = 900;
    public const CODE_LIFETIME = 120;

    private const RESPONSE_TYPE = 'code';
    private const CODE_CHALLENGE_METHOD = 'S256';
    private const CODE_CHALLENGE_PATTERN = '~^[A-Za-z0-9_-]{43}$~';
    private const IDENTIFIER_LENGTH = 64;
    private const IDENTIFIER_PATTERN = '~^[0-9a-f]{64}$~';
    private const MAXIMUM_STATE_LENGTH = 1024;
    private const REQUEST_PREFIX = 'request-';
    private const CODE_PREFIX = 'code-';
    private const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private FrontendInterface $oAuthCache,
        private OAuthClientService $clientService,
        private Random $random,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @throws OAuthException An unknown client or redirect uri, which must never be redirected to
     * @throws OAuthRedirectException Every other error, which goes back to the client
     * @throws Exception
     */
    public function createRequest(array $query, string $resource): AuthorizationRequest
    {
        $client = $this->clientService->findForAuthorization($this->getString($query, 'client_id'))
            ?? throw new OAuthException(OAuthException::INVALID_CLIENT, 'The client is not registered', 1763750001);
        $redirectUri = $this->getRedirectUri($client, $this->getString($query, 'redirect_uri'));
        $state = $this->getString($query, 'state');
        $codeChallenge = $this->getString($query, 'code_challenge');

        $isValidCodeChallenge = $this->isValidCodeChallenge(
            $client,
            $codeChallenge,
            $this->getString($query, 'code_challenge_method')
        );

        $error = null;
        if ($this->getString($query, 'response_type') !== self::RESPONSE_TYPE) {
            $error = [OAuthException::UNSUPPORTED_RESPONSE_TYPE, 'Only response_type "code" is supported'];
        } elseif ($isValidCodeChallenge === false) {
            $error = [OAuthException::INVALID_REQUEST, 'PKCE with code_challenge_method "S256" is required'];
        } elseif (strlen($state) > self::MAXIMUM_STATE_LENGTH) {
            $error = [OAuthException::INVALID_REQUEST, 'The state is too long'];
        } elseif ($this->isMatchingResource($this->getString($query, 'resource'), $resource) === false) {
            $error = [OAuthException::INVALID_TARGET, 'The resource has to be the url of this MCP endpoint'];
        }
        if ($error !== null) {
            throw new OAuthRedirectException($error[0], $error[1], 1763750002, $redirectUri, $state);
        }

        return new AuthorizationRequest(
            $client->getClientIdentifier(),
            $client->getClientName(),
            $redirectUri,
            $codeChallenge,
            $state,
            AccessScope::toScopeString(AccessScope::fromScopeString($this->getString($query, 'scope'))),
            $resource
        );
    }

    public function storeRequest(AuthorizationRequest $request): string
    {
        $identifier = $this->random->generateRandomHexString(self::IDENTIFIER_LENGTH);
        $this->oAuthCache->set(
            $this->getCacheEntry(self::REQUEST_PREFIX, $identifier),
            $request->toArray(),
            [],
            self::REQUEST_LIFETIME
        );
        return $identifier;
    }

    public function findRequest(string $identifier): ?AuthorizationRequest
    {
        $data = preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1
            ? $this->oAuthCache->get($this->getCacheEntry(self::REQUEST_PREFIX, $identifier))
            : false;
        return is_array($data) ? AuthorizationRequest::fromArray($data) : null;
    }

    public function removeRequest(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1) {
            $this->oAuthCache->remove($this->getCacheEntry(self::REQUEST_PREFIX, $identifier));
        }
    }

    // Read access only, if the user chose so or the client did not ask for more
    public function getApprovalUrl(AuthorizationRequest $request, int $backendUserIdentifier, bool $isReadOnly): string
    {
        $scopes = $isReadOnly || $request->isWriteAccessRequested() === false
            ? [AccessScope::Read]
            : [AccessScope::Read, AccessScope::Write];
        $code = $this->createCode($request, $backendUserIdentifier, AccessScope::toScopeString($scopes));

        return $this->getRedirectUrl($request->getRedirectUri(), [
            'code' => $code,
            'state' => $request->getState(),
            'iss' => $request->getResource(),
        ]);
    }

    public function getDenialUrl(AuthorizationRequest $request): string
    {
        return $this->getRedirectUrl($request->getRedirectUri(), [
            'error' => OAuthException::ACCESS_DENIED,
            'error_description' => 'The user denied the access',
            'state' => $request->getState(),
            'iss' => $request->getResource(),
        ]);
    }

    private function createCode(AuthorizationRequest $request, int $backendUserIdentifier, string $scope): string
    {
        $code = $this->random->generateRandomHexString(self::IDENTIFIER_LENGTH);
        $authorizationCode = AuthorizationCode::fromRequest(
            $request,
            $backendUserIdentifier,
            $scope,
            time() + self::CODE_LIFETIME
        );
        $this->oAuthCache->set(
            $this->getCacheEntry(self::CODE_PREFIX, $code),
            $authorizationCode->toArray(),
            [],
            self::CODE_LIFETIME
        );
        return $code;
    }

    // Removed as soon as it is read, whether the exchange succeeds or not
    public function consumeCode(string $code): ?AuthorizationCode
    {
        $authorizationCode = null;
        if (preg_match(self::IDENTIFIER_PATTERN, $code) === 1) {
            $cacheEntry = $this->getCacheEntry(self::CODE_PREFIX, $code);
            $data = $this->oAuthCache->get($cacheEntry);
            $this->oAuthCache->remove($cacheEntry);
            $authorizationCode = is_array($data) ? AuthorizationCode::fromArray($data) : null;
        }
        return $authorizationCode?->isValidAt(time()) ? $authorizationCode : null;
    }

    /**
     * @param array<string, string> $parameters
     */
    public function getRedirectUrl(string $redirectUri, array $parameters): string
    {
        $parameters = array_filter($parameters, static fn (string $value): bool => $value !== '');
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        return $redirectUri . $separator . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Without a redirect uri the only registered one is taken, as RFC 6749 allows it
     *
     * @throws OAuthException
     */
    private function getRedirectUri(OAuthClient $client, string $redirectUri): string
    {
        $registeredUris = $client->getRedirectUris();
        if ($redirectUri === '' && count($registeredUris) === 1) {
            $redirectUri = $registeredUris[0];
        }
        if ($redirectUri === '' || $client->isRedirectUriAllowed($redirectUri) === false) {
            throw new OAuthException(
                OAuthException::INVALID_REQUEST,
                'The redirect uri is not registered for this client',
                1763750003
            );
        }
        return $redirectUri;
    }

    // A client with a secret authenticates at the token endpoint and may leave PKCE out, a public client never
    private function isValidCodeChallenge(OAuthClient $client, string $codeChallenge, string $method): bool
    {
        $isPkce = $method === self::CODE_CHALLENGE_METHOD
            && preg_match(self::CODE_CHALLENGE_PATTERN, $codeChallenge) === 1;
        return $isPkce || ($client->hasSecret() && $codeChallenge === '' && $method === '');
    }

    // RFC 8707 - a client that names no resource gets a token for this endpoint, which is the only resource here
    private function isMatchingResource(string $requestedResource, string $resource): bool
    {
        return $requestedResource === '' || rtrim($requestedResource, '/') === rtrim($resource, '/');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getString(array $query, string $name): string
    {
        return is_string($query[$name] ?? null) ? trim($query[$name]) : '';
    }

    // Only the hash is the cache entry, so the content of the cache alone allows nothing
    private function getCacheEntry(string $prefix, string $identifier): string
    {
        return $prefix . hash(self::HASH_ALGORITHM, $identifier);
    }
}
