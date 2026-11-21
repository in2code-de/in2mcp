<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Model\AccessScope;
use Psr\Http\Message\ServerRequestInterface;

readonly class OAuthMetadataService
{
    private const RESOURCE_NAME = 'TYPO3 in2mcp';
    private const AUTHENTICATION_REALM = 'TYPO3 in2mcp';

    public function __construct(
        private ConfigurationService $configurationService,
        private EndpointUrlService $endpointUrlService,
    ) {
    }

    /**
     * RFC 9728 - the MCP endpoint names itself as its own authorization server, TYPO3 issues the tokens
     *
     * @return array<string, mixed>
     */
    public function getProtectedResourceMetadata(ServerRequestInterface $request): array
    {
        $resource = $this->endpointUrlService->getMcpServerUrl($request);
        return [
            'resource' => $resource,
            'authorization_servers' => [$resource],
            'scopes_supported' => $this->getScopes(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => self::RESOURCE_NAME,
        ];
    }

    /**
     * RFC 8414 - public clients with PKCE only, a client has no secret
     *
     * @return array<string, mixed>
     */
    public function getAuthorizationServerMetadata(ServerRequestInterface $request): array
    {
        return [
            'issuer' => $this->endpointUrlService->getMcpServerUrl($request),
            'authorization_endpoint' => $this->getUrl($request, $this->configurationService->getAuthorizationPath()),
            'token_endpoint' => $this->getUrl($request, $this->configurationService->getTokenPath()),
            'registration_endpoint' => $this->getUrl($request, $this->configurationService->getRegistrationPath()),
            'scopes_supported' => $this->getScopes(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    // Tells a client without credential where to start the authorization, RFC 9728 section 5.1
    public function getAuthenticationChallenge(ServerRequestInterface $request): string
    {
        $challenge = 'Bearer realm="' . self::AUTHENTICATION_REALM . '"';
        if ($this->configurationService->isOAuthActivated()) {
            $challenge .= ', resource_metadata="'
                . $this->getUrl($request, $this->configurationService->getProtectedResourceMetadataPath()) . '"'
                . ', scope="' . implode(' ', $this->getScopes()) . '"';
        }
        return $challenge;
    }

    /**
     * @return list<string>
     */
    private function getScopes(): array
    {
        return array_map(static fn (AccessScope $scope): string => $scope->value, AccessScope::cases());
    }

    private function getUrl(ServerRequestInterface $request, string $path): string
    {
        return $this->endpointUrlService->getUrl($request, $path);
    }
}
