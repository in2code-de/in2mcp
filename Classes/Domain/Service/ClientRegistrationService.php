<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Exception\OAuthException;
use TYPO3\CMS\Core\Crypto\Random;

/**
 * Dynamic client registration (RFC 7591) for public clients: a client gets an identifier for its redirect uris
 * and nothing else - no secret, no rights. What it may do is decided by the user on the consent page.
 */
readonly class ClientRegistrationService
{
    private const CLIENT_IDENTIFIER_LENGTH = 32;
    private const MAXIMUM_REDIRECT_URIS = 10;
    private const MAXIMUM_URI_LENGTH = 2000;
    private const AUTHENTICATION_METHOD = 'none';
    private const GRANT_TYPES = ['authorization_code', 'refresh_token'];
    private const RESPONSE_TYPES = ['code'];

    public function __construct(
        private OAuthClientRepository $clientRepository,
        private Random $random,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed> The registration response
     * @throws OAuthException
     * @throws Exception
     */
    public function register(array $metadata): array
    {
        $this->assertPublicClient($metadata);
        $client = new OAuthClient(
            $this->random->generateRandomHexString(self::CLIENT_IDENTIFIER_LENGTH),
            OAuthClient::cleanName($metadata['client_name'] ?? ''),
            $this->getRedirectUris($metadata),
            OAuthClient::TYPE_DYNAMIC
        );
        $this->clientRepository->insert($client);

        return [
            'client_id' => $client->getClientIdentifier(),
            'client_id_issued_at' => time(),
            'client_name' => $client->getClientName(),
            'redirect_uris' => $client->getRedirectUris(),
            'grant_types' => self::GRANT_TYPES,
            'response_types' => self::RESPONSE_TYPES,
            'token_endpoint_auth_method' => self::AUTHENTICATION_METHOD,
        ];
    }

    /**
     * Https anywhere, plain http only to the machine of the user (RFC 8252). No fragment, as the code is appended.
     */
    public function isAllowedRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        return $parts !== false
            && strlen($uri) <= self::MAXIMUM_URI_LENGTH
            && preg_match('~[\x00-\x20\x7F]~', $uri) !== 1
            && $host !== ''
            && isset($parts['fragment']) === false
            && isset($parts['user']) === false
            && ($scheme === 'https' || ($scheme === 'http' && in_array($host, OAuthClient::LOOPBACK_HOSTS, true)));
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws OAuthException
     */
    private function assertPublicClient(array $metadata): void
    {
        $method = $metadata['token_endpoint_auth_method'] ?? self::AUTHENTICATION_METHOD;
        if ($method !== self::AUTHENTICATION_METHOD) {
            throw new OAuthException(
                OAuthException::INVALID_CLIENT_METADATA,
                'Only public clients with PKCE are supported, token_endpoint_auth_method has to be "none"',
                1763740001
            );
        }

        $grantTypes = $metadata['grant_types'] ?? self::GRANT_TYPES;
        if (is_array($grantTypes) === false || array_diff($grantTypes, self::GRANT_TYPES) !== []) {
            throw new OAuthException(
                OAuthException::INVALID_CLIENT_METADATA,
                'Supported grant types are "authorization_code" and "refresh_token"',
                1763740002
            );
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return list<string>
     * @throws OAuthException
     */
    private function getRedirectUris(array $metadata): array
    {
        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (is_array($redirectUris) === false
            || $redirectUris === []
            || count($redirectUris) > self::MAXIMUM_REDIRECT_URIS
        ) {
            throw new OAuthException(
                OAuthException::INVALID_REDIRECT_URI,
                'redirect_uris has to be a list of one to ' . self::MAXIMUM_REDIRECT_URIS . ' uris',
                1763740003
            );
        }

        foreach ($redirectUris as $redirectUri) {
            if (is_string($redirectUri) === false || $this->isAllowedRedirectUri($redirectUri) === false) {
                throw new OAuthException(
                    OAuthException::INVALID_REDIRECT_URI,
                    'A redirect uri has to be an https url or a http url of localhost, without fragment',
                    1763740004
                );
            }
        }
        return array_values(array_unique($redirectUris));
    }
}
