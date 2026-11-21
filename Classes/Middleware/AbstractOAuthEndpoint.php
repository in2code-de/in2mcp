<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Exception\OAuthException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * An endpoint of the OAuth authorization: it exists only while the MCP server and OAuth are switched on, and it
 * answers only the allowed client addresses - like the MCP endpoint itself.
 */
abstract class AbstractOAuthEndpoint implements MiddlewareInterface
{
    private const PREFLIGHT_METHOD = 'OPTIONS';

    public function __construct(
        protected readonly ConfigurationService $configurationService,
        protected readonly ClientAddressService $clientAddressService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    abstract protected function getPath(): string;

    /**
     * @return list<string>
     */
    abstract protected function getAllowedMethods(): array;

    abstract protected function handle(ServerRequestInterface $request): ResponseInterface;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isEndpointRequest($request) === false || $this->isOAuthEnabled() === false) {
            return $handler->handle($request);
        }

        $allowedMethods = [...$this->getAllowedMethods(), self::PREFLIGHT_METHOD];
        if (in_array($request->getMethod(), $allowedMethods, true) === false) {
            return $this->withCors(new Response(null, 405), $allowedMethods)
                ->withHeader('Allow', implode(', ', $allowedMethods));
        }
        if ($this->clientAddressService->isAllowedClient($request) === false) {
            $this->logger->warning(
                'MCP: OAuth request from ' . $this->clientAddressService->getClientAddress($request) . ' was'
                . ' refused, the address is not in the allowed client addresses of the extension configuration'
            );
            return $this->withCors(new JsonResponse(['error' => 'Forbidden'], 403), $allowedMethods);
        }
        if ($request->getMethod() === self::PREFLIGHT_METHOD) {
            return $this->withCors(new Response(null, 204), $allowedMethods);
        }

        return $this->withCors($this->handle($request), $allowedMethods);
    }

    protected function getErrorResponse(OAuthException $exception, int $status = 400): ResponseInterface
    {
        return $this->withoutCaching(new JsonResponse($exception->toResponse(), $status));
    }

    protected function withoutCaching(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    /**
     * A client running in a browser has to reach the endpoints as well. No credentials are allowed, so a page of
     * another origin can not ride on a backend session.
     *
     * @param list<string> $allowedMethods
     */
    private function withCors(ResponseInterface $response, array $allowedMethods): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version');
    }

    private function isEndpointRequest(ServerRequestInterface $request): bool
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
