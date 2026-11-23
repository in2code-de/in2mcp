<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

readonly class OAuthClient
{
    public const TYPE_DYNAMIC = 'dcr';
    public const TYPE_METADATA_DOCUMENT = 'cimd';
    public const TYPE_MANUAL = 'manual';

    public const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public const AUTHENTICATION_METHOD_NONE = 'none';
    public const AUTHENTICATION_METHODS = [
        self::AUTHENTICATION_METHOD_NONE,
        'client_secret_basic',
        'client_secret_post',
    ];

    private const MAXIMUM_NAME_LENGTH = 100;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        private string $clientIdentifier,
        private string $clientName,
        private array $redirectUris,
        private string $registrationType,
        private string $secretHash = '',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string)($row['client_id'] ?? ''),
            (string)($row['client_name'] ?? ''),
            self::splitRedirectUris((string)($row['redirect_uris'] ?? '')),
            (string)($row['registration_type'] ?? ''),
            (string)($row['client_secret_hash'] ?? '')
        );
    }

    // One per line, so an administrator can maintain them in the backend form
    public function getRedirectUrisAsText(): string
    {
        return implode(PHP_EOL, $this->redirectUris);
    }

    /**
     * @return list<string>
     */
    private static function splitRedirectUris(string $redirectUris): array
    {
        return array_values(array_filter(array_map('trim', preg_split('~\R~', $redirectUris) ?: [])));
    }

    // The name is chosen by the client and shown on the consent page, so it is cut and cleaned of control characters
    public static function cleanName(mixed $name): string
    {
        $name = is_string($name) ? $name : '';
        return mb_substr(trim((string)preg_replace('~[\p{C}]+~u', ' ', $name)), 0, self::MAXIMUM_NAME_LENGTH);
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * @return list<string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    public function getRegistrationType(): string
    {
        return $this->registrationType;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function hasSecret(): bool
    {
        return $this->secretHash !== '';
    }

    // Only a client an administrator created with a secret may leave PKCE out - one that registered itself never
    public function isPkceOptional(): bool
    {
        return $this->hasSecret() && $this->registrationType === self::TYPE_MANUAL;
    }

    /**
     * Exact match, except for the port of a loopback address: a native client listens on a port it gets from the
     * system at the time of the login (RFC 8252 section 7.3)
     */
    public function isRedirectUriAllowed(string $redirectUri): bool
    {
        $allowed = in_array($redirectUri, $this->redirectUris, true);
        if ($allowed === false && $this->isLoopbackUri($redirectUri)) {
            $withoutPort = $this->removePort($redirectUri);
            foreach ($this->redirectUris as $registeredUri) {
                $allowed = $allowed || ($this->isLoopbackUri($registeredUri)
                    && $this->removePort($registeredUri) === $withoutPort);
            }
        }
        return $allowed;
    }

    private function isLoopbackUri(string $uri): bool
    {
        return parse_url($uri, PHP_URL_SCHEME) === 'http'
            && in_array(strtolower((string)parse_url($uri, PHP_URL_HOST)), self::LOOPBACK_HOSTS, true);
    }

    private function removePort(string $uri): string
    {
        return (string)preg_replace('~^(http://[^/:]+|http://\[[^]]+\]):[0-9]+~i', '$1', $uri);
    }
}
