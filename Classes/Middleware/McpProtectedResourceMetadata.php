<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use Psr\Http\Message\ServerRequestInterface;

// Below the endpoint and not at the root of the host: the url is announced in the 401 of the endpoint
class McpProtectedResourceMetadata extends AbstractOAuthMetadata
{
    protected function getPath(): string
    {
        return $this->configurationService->getProtectedResourceMetadataPath();
    }

    protected function getMetadata(ServerRequestInterface $request): array
    {
        return $this->oAuthMetadataService->getProtectedResourceMetadata($request);
    }
}
