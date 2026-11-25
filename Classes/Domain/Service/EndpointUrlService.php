<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

class EndpointUrlService
{
    private const NORMALIZED_PARAMS_ATTRIBUTE = 'normalizedParams';

    public function __construct(private readonly ConfigurationService $configurationService)
    {
    }

    public function getFileUploadUrl(): string
    {
        // A tool has no request of its own: this is the MCP request, set by BackendUserAuthenticator::prepareRequest()
        return $this->getRequestHost($GLOBALS['TYPO3_REQUEST'] ?? null)
            . $this->configurationService->getFileUploadPath();
    }

    public function getCurrentMcpServerUrl(): string
    {
        return $this->getRequestHost($GLOBALS['TYPO3_REQUEST'] ?? null)
            . $this->configurationService->getMcpServerPath();
    }

    // The resource an OAuth token is bound to, and the issuer of the tokens at the same time
    public function getMcpServerUrl(ServerRequestInterface $request): string
    {
        return $this->getUrl($request, $this->configurationService->getMcpServerPath());
    }

    public function getUrl(ServerRequestInterface $request, string $path): string
    {
        return $this->getRequestHost($request) . $path;
    }

    private function getRequestHost(mixed $request): string
    {
        $normalizedParams = $request instanceof ServerRequestInterface
            ? $request->getAttribute(self::NORMALIZED_PARAMS_ATTRIBUTE)
            : null;
        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRequestHost() : '';
    }
}
