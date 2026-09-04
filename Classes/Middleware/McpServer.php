<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\AuthenticationContext;
use In2code\In2mcp\Domain\Mcp\Authentication\BackendUserAuthenticator;
use In2code\In2mcp\Domain\Mcp\RateLimiter\RateLimiterFactory;
use In2code\In2mcp\Domain\Mcp\ServerFactory;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Exception\UserNotFoundException;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * Answers requests to the MCP endpoint with the MCP server instead of the TYPO3 backend.
 *
 * The middleware is registered in the backend stack before the backend routing, because the tools need a backend
 * user and a backend context, and because a request to the endpoint would otherwise end in a routing exception.
 */
class McpServer implements MiddlewareInterface
{
    private const AUTHENTICATION_REALM = 'TYPO3 in2mcp';

    public function __construct(
        protected readonly ServerFactory $serverFactory,
        protected readonly BackendUserAuthenticator $backendUserAuthenticator,
        protected readonly ConfigurationService $configurationService,
        protected readonly ResponseFactory $responseFactory,
        protected readonly StreamFactory $streamFactory,
        protected readonly RateLimiterFactory $rateLimiterFactory,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isMcpRequest($request) === false || $this->isMcpEnabled() === false) {
            return $handler->handle($request);
        }

        $request = $this->initializeRequestForMcp($request);

        $rateLimiter = $this->rateLimiterFactory->create($request);
        if ($rateLimiter->consume()->isAccepted() === false) {
            $this->logger->warning('MCP: Too many failed authentications, request was rejected');
            return $this->getErrorResponse(429, -32000, 'Too many requests');
        }

        $backendUserAuthentication = null;
        try {
            $backendUserAuthentication = $this->backendUserAuthenticator->authenticate($request);
            $rateLimiter->reset();
            return $this->serverFactory->get()->run($this->getTransport($request));
        } catch (UserNotFoundException $exception) {
            $this->logger->warning($exception->getMessage());
            return $this->getErrorResponse(401, -32001, 'Unauthorized')
                ->withHeader('WWW-Authenticate', 'Bearer realm="' . self::AUTHENTICATION_REALM . '"');
        } catch (Throwable $exception) {
            $this->logger->error('MCP: ' . $exception->getMessage(), ['exception' => $exception]);
            return $this->getErrorResponse(500, -32603, 'Internal error');
        } finally {
            if ($backendUserAuthentication instanceof BackendUserAuthentication) {
                $this->backendUserAuthenticator->removeSession($backendUserAuthentication);
            }
        }
    }

    private function getTransport(ServerRequestInterface $request): StreamableHttpTransport
    {
        return new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            $this->logger,
            [new CorsMiddleware(), new ProtocolVersionMiddleware()]
        );
    }

    private function getErrorResponse(int $statusCode, int $errorCode, string $message): ResponseInterface
    {
        return new JsonResponse(
            [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => $errorCode,
                    'message' => $message,
                ],
                'id' => null,
            ],
            $statusCode
        );
    }

    private function isMcpEnabled(): bool
    {
        try {
            return $this->configurationService->isMcpServerActivated();
        } catch (
            ExtensionConfigurationExtensionNotConfiguredException |
            ExtensionConfigurationPathDoesNotExistException $exception
        ) {
            $this->logger->error('MCP: ' . $exception->getMessage());
            return false;
        }
    }

    private function isMcpRequest(ServerRequestInterface $request): bool
    {
        return rtrim($request->getUri()->getPath(), '/')
            === rtrim($this->configurationService->getMcpServerPath(), '/');
    }

    private function initializeRequestForMcp(ServerRequestInterface $request): ServerRequestInterface
    {
        // A MCP request is authenticated by its api key alone. Dropping any backend session cookie here makes
        // sure that neither the authentication nor anything that runs afterwards can fall back to a browser
        // session, which the core would use before an authentication service is asked at all.
        $request = $request->withCookieParams([])
            ->withoutHeader('Cookie')
            ->withAttribute(
                AuthenticationContext::REQUEST_ATTRIBUTE,
                AuthenticationContext::fromRequest($request)
            );

        // Parts of TYPO3 read their configuration and their environment from the global request, which is not
        // set in this early state of the backend request.
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }
}
