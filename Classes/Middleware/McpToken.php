<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Mcp\RateLimiter\RateLimiterFactory;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\EndpointUrlService;
use In2code\In2mcp\Domain\Service\TokenRequestService;
use In2code\In2mcp\Exception\OAuthException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Exchanges an authorization code or a refresh token for new tokens. A failing request counts against the rate
 * limit of the address like a failing MCP authentication, a successful one resets it.
 */
class McpToken extends AbstractOAuthEndpoint
{
    public function __construct(
        ConfigurationService $configurationService,
        ClientAddressService $clientAddressService,
        LoggerInterface $logger,
        private readonly TokenRequestService $tokenRequestService,
        private readonly EndpointUrlService $endpointUrlService,
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
        parent::__construct($configurationService, $clientAddressService, $logger);
    }

    protected function getPath(): string
    {
        return $this->configurationService->getTokenPath();
    }

    protected function getAllowedMethods(): array
    {
        return ['POST'];
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        $rateLimiter = $this->rateLimiterFactory->create($request);
        if ($rateLimiter->consume()->isAccepted() === false) {
            $this->logger->warning('MCP: Too many failed token requests, request was rejected');
            return $this->withoutCaching(new JsonResponse(['error' => 'Too many requests'], 429));
        }

        try {
            $tokens = $this->tokenRequestService->handle(
                $this->getParameters($request),
                $this->endpointUrlService->getMcpServerUrl($request)
            );
            $rateLimiter->reset();
            return $this->withoutCaching(new JsonResponse($tokens->toResponse()));
        } catch (OAuthException $exception) {
            $this->logger->warning('MCP: Token request was refused: ' . $exception->getMessage());
            return $this->getErrorResponse(
                $exception,
                $exception->getError() === OAuthException::INVALID_CLIENT ? 401 : 400
            );
        } catch (Throwable $exception) {
            $this->logger->error('MCP: Token request failed: ' . $exception->getMessage());
            return $this->getErrorResponse(
                new OAuthException(OAuthException::SERVER_ERROR, 'The token could not be issued', 1763760110),
                500
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getParameters(ServerRequestInterface $request): array
    {
        $parameters = $request->getParsedBody();
        return is_array($parameters) ? $parameters : [];
    }
}
