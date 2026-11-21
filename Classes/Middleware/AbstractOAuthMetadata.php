<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\OAuthMetadataService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

abstract class AbstractOAuthMetadata implements MiddlewareInterface
{
    private const ALLOWED_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const PREFLIGHT_METHOD = 'OPTIONS';
    private const MAXIMUM_AGE = 3600;

    public function __construct(
        protected readonly ConfigurationService $configurationService,
        protected readonly OAuthMetadataService $oAuthMetadataService,
        private readonly ClientAddressService $clientAddressService,
        private readonly LoggerInterface $logger,
    ) {
    }

    abstract protected function getPath(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function getMetadata(ServerRequestInterface $request): array;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isMetadataRequest($request) === false || $this->isOAuthEnabled() === false) {
            return $handler->handle($request);
        }

        if (in_array($request->getMethod(), self::ALLOWED_METHODS, true) === false) {
            return $this->withCors(new Response(null, 405))->withHeader('Allow', implode(', ', self::ALLOWED_METHODS));
        }
        if ($this->clientAddressService->isAllowedClient($request) === false) {
            return $this->withCors(new JsonResponse(['error' => 'Forbidden'], 403));
        }
        if ($request->getMethod() === self::PREFLIGHT_METHOD) {
            return $this->withCors(new Response(null, 204));
        }

        return $this->withCors(new JsonResponse($this->getMetadata($request)))
            ->withHeader('Cache-Control', 'public, max-age=' . self::MAXIMUM_AGE);
    }

    // Public metadata, which a client running in a browser has to be able to read as well
    private function withCors(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', implode(', ', self::ALLOWED_METHODS))
            ->withHeader('Access-Control-Allow-Headers', 'MCP-Protocol-Version');
    }

    private function isMetadataRequest(ServerRequestInterface $request): bool
    {
        return rtrim($request->getUri()->getPath(), '/') === $this->getPath();
    }

    private function isOAuthEnabled(): bool
    {
        try {
            return $this->configurationService->isMcpServerActivated()
                && $this->configurationService->isOAuthActivated();
        } catch (Throwable $exception) {
            $this->logger->error('MCP: ' . $exception->getMessage());
            return false;
        }
    }
}
