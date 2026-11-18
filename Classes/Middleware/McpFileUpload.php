<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\AuthenticationContext;
use In2code\In2mcp\Domain\Mcp\Authentication\BackendUserAuthenticator;
use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Mcp\RateLimiter\RateLimiterFactory;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\FileUploadService;
use In2code\In2mcp\Domain\Service\UploadTokenService;
use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Receives a file for a one-time token that the tool "create_file_upload" handed out. The token stands for the
 * backend user who requested it, who is then initialized exactly like for a MCP request.
 */
class McpFileUpload implements MiddlewareInterface
{
    private const METHOD = 'POST';

    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly ClientAddressService $clientAddressService,
        private readonly RateLimiterFactory $rateLimiterFactory,
        private readonly UploadTokenService $uploadTokenService,
        private readonly BackendUserAuthenticator $backendUserAuthenticator,
        private readonly FileUploadService $fileUploadService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isUploadRequest($request) === false || $this->isUploadEnabled() === false) {
            return $handler->handle($request);
        }

        if ($request->getMethod() !== self::METHOD) {
            return $this->getErrorResponse(405, 'Only POST is accepted')->withHeader('Allow', self::METHOD);
        }

        if ($this->clientAddressService->isAllowedClient($request) === false) {
            $this->logger->warning(
                'MCP: Upload from ' . $this->clientAddressService->getClientAddress($request) . ' was refused, the'
                . ' address is not in the allowed client addresses of the extension configuration'
            );
            return $this->getErrorResponse(403, 'Forbidden');
        }

        $rateLimiter = $this->rateLimiterFactory->create($request);
        if ($rateLimiter->consume()->isAccepted() === false) {
            $this->logger->warning('MCP: Too many failed authentications, upload was rejected');
            return $this->getErrorResponse(429, 'Too many requests');
        }

        $uploadToken = $this->uploadTokenService->consume(
            trim($request->getHeaderLine(FileUploadService::TOKEN_HEADER))
        );
        if ($uploadToken === null) {
            $this->logger->warning('MCP: Upload with an unknown, used or expired token was refused');
            return $this->getErrorResponse(
                401,
                'The upload token is unknown, already used or expired. Call "create_file_upload" for a new one.'
            );
        }

        $request = $this->backendUserAuthenticator->prepareRequest(
            $request,
            AuthenticationContext::forUploadUser($uploadToken->getUserIdentifier())
        );

        $backendUserAuthentication = null;
        try {
            $backendUserAuthentication = $this->backendUserAuthenticator->authenticate($request);
            $rateLimiter->reset();
            $result = $this->fileUploadService->storeUploadedFile($this->getUploadedFile($request), $uploadToken);
            return new JsonResponse(['imported' => true] + $result);
        } catch (UserNotFoundException $exception) {
            $this->logger->warning($exception->getMessage());
            return $this->getErrorResponse(401, 'Unauthorized');
        } catch (ClientFacingException $exception) {
            return $this->getErrorResponse(400, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('MCP: Upload failed: ' . $exception->getMessage(), ['exception' => $exception]);
            return $this->getErrorResponse(500, 'Internal error');
        } finally {
            if ($backendUserAuthentication instanceof BackendUserAuthentication) {
                $this->backendUserAuthenticator->removeSession($backendUserAuthentication);
            }
        }
    }

    private function getUploadedFile(ServerRequestInterface $request): ?UploadedFileInterface
    {
        $uploadedFile = $request->getUploadedFiles()[FileUploadService::FILE_FIELD] ?? null;
        return $uploadedFile instanceof UploadedFileInterface ? $uploadedFile : null;
    }

    private function getErrorResponse(int $statusCode, string $message): ResponseInterface
    {
        return new JsonResponse(['imported' => false, 'error' => $message], $statusCode);
    }

    private function isUploadRequest(ServerRequestInterface $request): bool
    {
        return rtrim($request->getUri()->getPath(), '/') === $this->configurationService->getFileUploadPath();
    }

    private function isUploadEnabled(): bool
    {
        try {
            return $this->configurationService->isMcpServerActivated()
                && $this->configurationService->isFileUploadActivated();
        } catch (Throwable $exception) {
            $this->logger->error('MCP: ' . $exception->getMessage());
            return false;
        }
    }
}
