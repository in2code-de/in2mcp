<?php

declare(strict_types=1);

namespace In2code\In2mcp\Controller;

use In2code\In2mcp\Domain\Model\AuthorizationRequest;
use In2code\In2mcp\Domain\Service\AuthorizationService;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Exception\UserNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * The consent page of the OAuth authorization. The route is "public" only so that its url needs no backend token,
 * which the authorization endpoint can not create without a session - a backend login is still required, the
 * core redirects to the login form and back here. The decision itself is protected by a form token.
 */
#[AsController]
readonly class AuthorizationController
{
    public const ROUTE = 'in2mcp_authorize';
    public const REQUEST_PARAMETER = 'request';

    private const TEMPLATE = 'Authorization/Consent';
    private const FORM_NAME = 'in2mcp';
    private const FORM_ACTION = 'authorize';
    private const DECISION_ALLOW = 'allow';
    private const ACCESS_READ = 'read';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private FormProtectionFactory $formProtectionFactory,
        private AuthorizationService $authorizationService,
        private BackendUserService $backendUserService,
        private UriBuilder $uriBuilder,
    ) {
    }

    /**
     * @throws UserNotFoundException
     */
    public function consentAction(ServerRequestInterface $request): ResponseInterface
    {
        return $request->getMethod() === 'POST' ? $this->decide($request) : $this->show($request);
    }

    /**
     * @throws UserNotFoundException
     */
    private function show(ServerRequestInterface $request): ResponseInterface
    {
        $identifier = (string)($request->getQueryParams()[self::REQUEST_PARAMETER] ?? '');
        $authorizationRequest = $this->authorizationService->findRequest($identifier);
        $formProtection = $this->formProtectionFactory->createFromRequest($request);
        $formToken = $formProtection->generateToken(self::FORM_NAME, self::FORM_ACTION, $identifier);
        $formProtection->persistSessionToken();

        return $this->render($request, $authorizationRequest, 200, [
            'requestIdentifier' => $identifier,
            'formToken' => $formToken,
            'actionUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
        ]);
    }

    /**
     * @throws UserNotFoundException
     */
    private function decide(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $identifier = (string)($body[self::REQUEST_PARAMETER] ?? '');
        $isValidToken = $this->formProtectionFactory->createFromRequest($request)->validateToken(
            (string)($body['formToken'] ?? ''),
            self::FORM_NAME,
            self::FORM_ACTION,
            $identifier
        );
        $authorizationRequest = $isValidToken ? $this->authorizationService->findRequest($identifier) : null;
        if ($authorizationRequest === null) {
            return $this->render($request, null, 400);
        }

        $this->authorizationService->removeRequest($identifier);
        $redirectUrl = ($body['decision'] ?? '') === self::DECISION_ALLOW && $this->backendUserService->hasMcpAccess()
            ? $this->authorizationService->getApprovalUrl(
                $authorizationRequest,
                $this->backendUserService->getUserId(),
                ($body['access'] ?? '') === self::ACCESS_READ
            )
            : $this->authorizationService->getDenialUrl($authorizationRequest);
        return new RedirectResponse($redirectUrl, 303);
    }

    /**
     * @param array<string, string> $variables
     * @throws UserNotFoundException
     */
    private function render(
        ServerRequestInterface $request,
        ?AuthorizationRequest $authorizationRequest,
        int $status,
        array $variables = []
    ): ResponseInterface {
        $view = $this->moduleTemplateFactory->create($request);
        $view->assignMultiple($variables + [
            'authorizationRequest' => $authorizationRequest,
            'username' => $this->backendUserService->getBackendUser()->user['username'] ?? '',
            'siteName' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '',
            'hasMcpAccess' => $this->backendUserService->hasMcpAccess(),
        ]);
        return $view->renderResponse(self::TEMPLATE)->withStatus($status);
    }
}
