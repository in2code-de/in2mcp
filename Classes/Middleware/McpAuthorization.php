<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Controller\AuthorizationController;
use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Mcp\RateLimiter\RateLimiterFactory;
use In2code\In2mcp\Domain\Service\AuthorizationService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\EndpointUrlService;
use In2code\In2mcp\Exception\OAuthException;
use In2code\In2mcp\Exception\OAuthRedirectException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;

/**
 * Checks the authorization request of a client and hands it over to the consent page in the backend, which asks
 * for a backend login first if there is none. Only the identifier of the request travels through the browser.
 */
class McpAuthorization extends AbstractOAuthEndpoint
{
    public function __construct(
        ConfigurationService $configurationService,
        ClientAddressService $clientAddressService,
        LoggerInterface $logger,
        private readonly AuthorizationService $authorizationService,
        private readonly EndpointUrlService $endpointUrlService,
        private readonly UriBuilder $uriBuilder,
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
        parent::__construct($configurationService, $clientAddressService, $logger);
    }

    protected function getPath(): string
    {
        return $this->configurationService->getAuthorizationPath();
    }

    protected function getAllowedMethods(): array
    {
        return ['GET'];
    }

    // Every request is counted, also a valid one: it stores a request and may fetch a client id metadata document
    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->rateLimiterFactory->create($request)->consume()->isAccepted() === false) {
            $this->logger->warning('MCP: Too many authorization requests, request was rejected');
            return $this->getErrorPage('Too many requests, please try again later', 429);
        }

        try {
            $authorizationRequest = $this->authorizationService->createRequest(
                $request->getQueryParams(),
                $this->endpointUrlService->getMcpServerUrl($request)
            );
            // The host of this request, the global request that an absolute url of the UriBuilder needs is not set yet
            $consentPath = (string)$this->uriBuilder->buildUriFromRoute(
                AuthorizationController::ROUTE,
                [AuthorizationController::REQUEST_PARAMETER => $this->authorizationService->storeRequest(
                    $authorizationRequest
                )],
                UriBuilder::ABSOLUTE_PATH
            );
            return new RedirectResponse($this->endpointUrlService->getUrl($request, $consentPath), 303);
        } catch (OAuthRedirectException $exception) {
            return new RedirectResponse($this->authorizationService->getRedirectUrl(
                $exception->getRedirectUri(),
                $exception->toResponse() + [
                    'state' => $exception->getState(),
                    'iss' => $this->endpointUrlService->getMcpServerUrl($request),
                ]
            ), 303);
        } catch (OAuthException $exception) {
            return $this->getErrorPage($exception->getMessage(), 400);
        } catch (Throwable $exception) {
            $this->logger->error('MCP: Authorization request failed: ' . $exception->getMessage());
            return $this->getErrorPage('The authorization request could not be processed', 500);
        }
    }

    // Plain text, because the user reads it in the browser and nothing of the request may become markup
    private function getErrorPage(string $message, int $status): ResponseInterface
    {
        $response = new Response(null, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->getBody()->write('MCP authorization failed: ' . $message);
        return $this->withoutCaching($response);
    }
}
