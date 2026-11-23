<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\AuthorizationCode;
use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Model\OAuthTokens;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Exception\OAuthException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

readonly class TokenRequestService
{
    private const GRANT_AUTHORIZATION_CODE = 'authorization_code';
    // security-lint: ignore hardcoded-credential - the name of the OAuth grant type, no secret
    private const GRANT_REFRESH_TOKEN = 'refresh_token';
    private const CODE_VERIFIER_PATTERN = '~^[A-Za-z0-9._\~-]{43,128}$~';
    private const BASIC_SCHEME = 'Basic ';
    private const HASH_MODE = 'BE';

    public function __construct(
        private AuthorizationService $authorizationService,
        private OAuthTokenService $tokenService,
        private OAuthClientRepository $clientRepository,
        private PasswordHashFactory $passwordHashFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters The form parameters of the token request
     * @param string $authorization The Authorization header, which carries the credentials of a client with secret
     * @throws OAuthException
     * @throws Exception
     */
    public function handle(array $parameters, string $resource, string $authorization = ''): OAuthTokens
    {
        $client = $this->authenticateClient($parameters, $authorization);

        return match ($this->getString($parameters, 'grant_type')) {
            self::GRANT_AUTHORIZATION_CODE => $this->exchangeCode($parameters, $client, $resource),
            self::GRANT_REFRESH_TOKEN => $this->tokenService->refresh(
                $this->getString($parameters, 'refresh_token'),
                $client->getClientIdentifier()
            ),
            default => throw new OAuthException(
                OAuthException::UNSUPPORTED_GRANT_TYPE,
                'Supported grant types are "authorization_code" and "refresh_token"',
                1763760102
            ),
        };
    }

    /**
     * A public client names itself, a client with a secret has to prove it - in the Authorization header
     * (client_secret_basic) or in the form (client_secret_post)
     *
     * @param array<string, mixed> $parameters
     * @throws OAuthException
     * @throws Exception
     */
    private function authenticateClient(array $parameters, string $authorization): OAuthClient
    {
        [$clientIdentifier, $secret] = $this->getBasicCredentials($authorization)
            ?? [$this->getString($parameters, 'client_id'), $this->getString($parameters, 'client_secret')];

        $client = $this->clientRepository->findByClientIdentifier($clientIdentifier);
        if ($client === null || ($client->hasSecret() && $this->isValidSecret($client, $secret) === false)) {
            throw new OAuthException(
                OAuthException::INVALID_CLIENT,
                'The client is not registered or its secret is wrong',
                1763760101
            );
        }
        return $client;
    }

    /**
     * RFC 6749 section 2.3.1: both parts are form encoded before they are joined
     *
     * @return array{0: string, 1: string}|null
     */
    private function getBasicCredentials(string $authorization): ?array
    {
        $credentials = null;
        if (stripos($authorization, self::BASIC_SCHEME) === 0) {
            $decoded = (string)base64_decode(substr($authorization, strlen(self::BASIC_SCHEME)), true);
            if (str_contains($decoded, ':')) {
                [$clientIdentifier, $secret] = explode(':', $decoded, 2);
                $credentials = [urldecode($clientIdentifier), urldecode($secret)];
            }
        }
        return $credentials;
    }

    private function isValidSecret(OAuthClient $client, string $secret): bool
    {
        try {
            return $secret !== '' && $this->passwordHashFactory
                ->get($client->getSecretHash(), self::HASH_MODE)
                ->checkPassword($secret, $client->getSecretHash());
        } catch (InvalidPasswordHashException) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @throws OAuthException
     * @throws Exception
     */
    private function exchangeCode(array $parameters, OAuthClient $client, string $resource): OAuthTokens
    {
        $code = $this->authorizationService->consumeCode($this->getString($parameters, 'code'))
            ?? throw new OAuthException(
                OAuthException::INVALID_GRANT,
                'The authorization code is unknown, used or expired',
                1763760103
            );

        $isValid = hash_equals($code->getClientIdentifier(), $client->getClientIdentifier())
            && hash_equals($code->getRedirectUri(), $this->getString($parameters, 'redirect_uri'))
            && $this->isValidCodeVerifier($code, $client, $this->getString($parameters, 'code_verifier'));
        if ($isValid === false) {
            throw new OAuthException(
                OAuthException::INVALID_GRANT,
                'Client, redirect uri or code verifier do not match the authorization code',
                1763760104
            );
        }

        $requestedResource = $this->getString($parameters, 'resource');
        if (($requestedResource !== '' && rtrim($requestedResource, '/') !== rtrim($resource, '/'))
            || rtrim($code->getResource(), '/') !== rtrim($resource, '/')
        ) {
            throw new OAuthException(
                OAuthException::INVALID_TARGET,
                'The resource has to be the url of this MCP endpoint',
                1763760105
            );
        }

        return $this->tokenService->issue(
            $code->getBackendUserIdentifier(),
            $client->getClientIdentifier(),
            $code->getScope(),
            $code->getResource(),
            $client->getClientName()
        );
    }

    /**
     * PKCE S256 (RFC 7636): BASE64URL(SHA256(code_verifier)) has to be the code challenge of the request. Only a
     * client that proved its secret may have gone without one.
     */
    private function isValidCodeVerifier(AuthorizationCode $code, OAuthClient $client, string $codeVerifier): bool
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $isMatchingVerifier = preg_match(self::CODE_VERIFIER_PATTERN, $codeVerifier) === 1
            && hash_equals($code->getCodeChallenge(), $challenge);

        return $code->getCodeChallenge() === ''
            ? $client->isPkceOptional() && $codeVerifier === ''
            : $isMatchingVerifier;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function getString(array $parameters, string $name): string
    {
        return is_string($parameters[$name] ?? null) ? trim($parameters[$name]) : '';
    }
}
