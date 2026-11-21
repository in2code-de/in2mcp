<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\OAuthMetadataService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

abstract class AbstractOAuthMetadata extends AbstractOAuthEndpoint
{
    private const MAXIMUM_AGE = 3600;

    public function __construct(
        ConfigurationService $configurationService,
        ClientAddressService $clientAddressService,
        LoggerInterface $logger,
        protected readonly OAuthMetadataService $oAuthMetadataService,
    ) {
        parent::__construct($configurationService, $clientAddressService, $logger);
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function getMetadata(ServerRequestInterface $request): array;

    protected function getAllowedMethods(): array
    {
        return ['GET', 'HEAD'];
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new JsonResponse($this->getMetadata($request)))
            ->withHeader('Cache-Control', 'public, max-age=' . self::MAXIMUM_AGE);
    }
}
