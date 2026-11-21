<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\AuthorizationCode;
use In2code\In2mcp\Domain\Model\OAuthTokens;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Exception\OAuthException;

readonly class TokenRequestService
{
    private const GRANT_AUTHORIZATION_CODE = 'authorization_code';
    // security-lint: ignore hardcoded-credential - the name of the OAuth grant type, no secret
    private const GRANT_REFRESH_TOKEN = 'refresh_token';
    private const CODE_VERIFIER_PATTERN = '~^[A-Za-z0-9._\~-]{43,128}$~';

    public function __construct(
        private AuthorizationService $authorizationService,
        private OAuthTokenService $tokenService,
        private OAuthClientRepository $clientRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters The form parameters of the token request
     * @throws OAuthException
     * @throws Exception
     */
    public function handle(array $parameters, string $resource): OAuthTokens
    {
        $clientIdentifier = $this->getString($parameters, 'client_id');
        if ($this->clientRepository->findByClientIdentifier($clientIdentifier) === null) {
            throw new OAuthException(OAuthException::INVALID_CLIENT, 'The client is not registered', 1763760101);
        }

        return match ($this->getString($parameters, 'grant_type')) {
            self::GRANT_AUTHORIZATION_CODE => $this->exchangeCode($parameters, $clientIdentifier, $resource),
            self::GRANT_REFRESH_TOKEN => $this->tokenService->refresh(
                $this->getString($parameters, 'refresh_token'),
                $clientIdentifier
            ),
            default => throw new OAuthException(
                OAuthException::UNSUPPORTED_GRANT_TYPE,
                'Supported grant types are "authorization_code" and "refresh_token"',
                1763760102
            ),
        };
    }

    /**
     * @param array<string, mixed> $parameters
     * @throws OAuthException
     * @throws Exception
     */
    private function exchangeCode(array $parameters, string $clientIdentifier, string $resource): OAuthTokens
    {
        $code = $this->authorizationService->consumeCode($this->getString($parameters, 'code'))
            ?? throw new OAuthException(
                OAuthException::INVALID_GRANT,
                'The authorization code is unknown, used or expired',
                1763760103
            );

        $isValid = hash_equals($code->getClientIdentifier(), $clientIdentifier)
            && hash_equals($code->getRedirectUri(), $this->getString($parameters, 'redirect_uri'))
            && $this->isValidCodeVerifier($code, $this->getString($parameters, 'code_verifier'));
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
            $clientIdentifier,
            $code->getScope(),
            $code->getResource()
        );
    }

    // PKCE S256 (RFC 7636): BASE64URL(SHA256(code_verifier)) has to be the code challenge of the request
    private function isValidCodeVerifier(AuthorizationCode $code, string $codeVerifier): bool
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        return preg_match(self::CODE_VERIFIER_PATTERN, $codeVerifier) === 1
            && hash_equals($code->getCodeChallenge(), $challenge);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function getString(array $parameters, string $name): string
    {
        return is_string($parameters[$name] ?? null) ? trim($parameters[$name]) : '';
    }
}
