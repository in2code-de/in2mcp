<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The only part of in2mcp in the frontend stack: RFC 8414 puts the metadata of the issuer "/typo3/mcp" at
 * "/.well-known/oauth-authorization-server/typo3/mcp", outside of the backend. It runs before the site is
 * resolved, so it answers on a host without a site configuration as well.
 */
class McpAuthorizationServerMetadata extends AbstractOAuthMetadata
{
    protected function getPath(): string
    {
        return $this->configurationService->getAuthorizationServerMetadataPath();
    }

    protected function getMetadata(ServerRequestInterface $request): array
    {
        return $this->oAuthMetadataService->getAuthorizationServerMetadata($request);
    }
}
