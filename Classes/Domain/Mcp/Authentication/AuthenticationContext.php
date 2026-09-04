<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Authentication;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Carries the api key of a MCP request through the authentication and records the backend user it belongs to.
 *
 * The context is the marker of a MCP request: it is put on the request by the McpServer middleware alone, so an
 * authentication service never answers for any other request, and the recorded user lets the authenticator
 * verify that the backend user it ends up with is exactly the one the api key belongs to.
 */
class AuthenticationContext
{
    public const REQUEST_ATTRIBUTE = 'in2mcp.mcp';

    /**
     * Header names that may carry the api key. "Authorization" is the standard, "X-Api-Key" and "Api-Key" are
     * fallbacks for clients that can not set an authorization header and for webserver configurations that do
     * not pass it to php (e.g. apache with mod_proxy_fcgi without "CGIPassAuth On").
     */
    protected const API_KEY_HEADERS = ['X-Api-Key', 'Api-Key'];

    protected const AUTHORIZATION_HEADER = 'Authorization';
    protected const AUTHORIZATION_SCHEME = 'Bearer';

    protected ?int $authenticatedUserIdentifier = null;

    public function __construct(protected readonly string $apiKey)
    {
    }

    /**
     * Reads the api key from an "Authorization: Bearer <key>", a "X-Api-Key: <key>" or an "Api-Key: <key>" header
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $authorization = trim($request->getHeaderLine(self::AUTHORIZATION_HEADER));
        if (stripos($authorization, self::AUTHORIZATION_SCHEME . ' ') === 0) {
            return new self(trim(substr($authorization, strlen(self::AUTHORIZATION_SCHEME) + 1)));
        }

        foreach (self::API_KEY_HEADERS as $header) {
            $apiKey = trim($request->getHeaderLine($header));
            if ($apiKey !== '') {
                return new self($apiKey);
            }
        }

        return new self('');
    }

    public static function fromRequestAttribute(ServerRequestInterface $request): ?self
    {
        $context = $request->getAttribute(self::REQUEST_ATTRIBUTE);
        return $context instanceof self ? $context : null;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function setAuthenticatedUserIdentifier(int $authenticatedUserIdentifier): void
    {
        $this->authenticatedUserIdentifier = $authenticatedUserIdentifier;
    }

    public function getAuthenticatedUserIdentifier(): ?int
    {
        return $this->authenticatedUserIdentifier;
    }

    public function isAuthenticatedUser(int $userIdentifier): bool
    {
        return $this->authenticatedUserIdentifier !== null && $this->authenticatedUserIdentifier === $userIdentifier;
    }
}
