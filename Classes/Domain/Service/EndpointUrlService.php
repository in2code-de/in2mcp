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
        return $this->getRequestHost() . $this->configurationService->getFileUploadPath();
    }

    private function getRequestHost(): string
    {
        // A tool has no request of its own: this is the MCP request, set by BackendUserAuthenticator::prepareRequest()
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $normalizedParams = $request instanceof ServerRequestInterface
            ? $request->getAttribute(self::NORMALIZED_PARAMS_ATTRIBUTE)
            : null;
        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRequestHost() : '';
    }
}
