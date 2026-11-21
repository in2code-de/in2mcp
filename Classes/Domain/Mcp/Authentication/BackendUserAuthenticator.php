<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Authentication;

use In2code\In2mcp\Exception\UserNotFoundException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaRequiredException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A MCP client has no backend session, so the backend user of the api key is initialized exactly like the backend
 * does it for a regular request. Every read and write of the tools then runs with the permissions of that user.
 */
class BackendUserAuthenticator
{
    /**
     * Custom permission option of a backend group, registered in ext_localconf.php
     */
    public const PERMISSION_OPTIONS = 'in2mcp';
    public const PERMISSION_ACCESS = 'access';

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly LanguageServiceFactory $languageServiceFactory,
        protected readonly Context $context
    ) {
    }

    /**
     * @throws UserNotFoundException
     */
    public function authenticate(ServerRequestInterface $request): BackendUserAuthentication
    {
        $authenticationContext = AuthenticationContext::fromRequestAttribute($request);
        if ($authenticationContext === null) {
            throw new UserNotFoundException('MCP: Request does not provide an api key', 1756800304);
        }
        if ($authenticationContext->getUploadUserIdentifier() === null) {
            $this->assertWellFormedCredential($authenticationContext);
        }

        // Authentication services are only asked for a user if a login form was submitted or if this option is
        // set. It is enabled for the current MCP request only.
        $GLOBALS['TYPO3_CONF_VARS']['SVCONF']['auth']['setup']['BE_fetchUserIfNoSession'] = true;

        $backendUserAuthentication = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        try {
            $backendUserAuthentication->start($request);
        } catch (MfaRequiredException) {
            // The api key itself is the credential of the client, and an OAuth token is only issued after a backend
            // login that included the second factor. So a multi factor authentication (which a client without a
            // browser can not solve) is skipped - but only for a user the credential authenticated. Without that
            // check a session that is still waiting for its second factor would be let through.
            if ($authenticationContext->getAuthenticatedUserIdentifier() === null) {
                $this->logger->warning('MCP: Request without a valid credential needs a multi factor authentication');
                throw new UserNotFoundException('MCP: Request could not be authenticated', 1756800308);
            }
        }

        $this->assertUserOfCredential($backendUserAuthentication, $authenticationContext);
        $this->assertLoginAllowed($backendUserAuthentication);

        $GLOBALS['BE_USER'] = $backendUserAuthentication;
        $this->setBackendUserAspect($backendUserAuthentication);
        $this->initializeBackendUserWithoutLogin($backendUserAuthentication);
        $this->assertMcpAccess($backendUserAuthentication);
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUserAuthentication);
        $this->setBackendUserAspect($backendUserAuthentication);

        return $backendUserAuthentication;
    }

    /**
     * A MCP request is authenticated by its credential alone. Dropping any backend session cookie makes sure that
     * neither the authentication nor anything that runs afterwards can fall back to a browser session, which the
     * core would use before an authentication service is asked at all.
     */
    public function prepareRequest(
        ServerRequestInterface $request,
        AuthenticationContext $authenticationContext
    ): ServerRequestInterface {
        $request = $request->withCookieParams([])
            ->withoutHeader('Cookie')
            ->withAttribute(AuthenticationContext::REQUEST_ATTRIBUTE, $authenticationContext);

        // Parts of TYPO3 read their configuration and their environment from the global request, which is not
        // set in this early state of the backend request.
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    /**
     * The middleware has removed every cookie of the request, so a request without a credential can not be
     * authenticated at all and never has to reach the authentication of the core.
     *
     * @throws UserNotFoundException
     */
    protected function assertWellFormedCredential(AuthenticationContext $authenticationContext): void
    {
        if ($authenticationContext->hasCredential() === false) {
            throw new UserNotFoundException('MCP: Request does not provide an api key', 1756800304);
        }
        if ($authenticationContext->isAccessToken() && $authenticationContext->hasWellFormedAccessToken() === false) {
            throw new UserNotFoundException('MCP: Request provides a malformed access token', 1763712000);
        }
        if ($authenticationContext->isAccessToken() === false
            && $authenticationContext->hasWellFormedApiKey() === false
        ) {
            throw new UserNotFoundException(
                'MCP: Request provides an api key that is malformed or too short',
                1756800320
            );
        }
    }

    /**
     * Whatever the authentication of the core ended up with, it has to be the user the credential belongs to. Only
     * McpAuthenticationService records that user, so a user from any other source is refused here.
     *
     * @throws UserNotFoundException
     */
    protected function assertUserOfCredential(
        BackendUserAuthentication $backendUserAuthentication,
        AuthenticationContext $authenticationContext
    ): void {
        $userIdentifier = (int)($backendUserAuthentication->user['uid'] ?? 0);
        if ($userIdentifier === 0 || $authenticationContext->isAuthenticatedUser($userIdentifier) === false) {
            throw new UserNotFoundException('MCP: Request could not be authenticated', 1756800300);
        }
    }

    /**
     * @throws UserNotFoundException
     */
    protected function assertLoginAllowed(BackendUserAuthentication $backendUserAuthentication): void
    {
        // The core checks [BE][adminOnly] in its authentication middleware, which a MCP request never reaches
        if ($backendUserAuthentication->isUserAllowedToLogin() === false) {
            $this->removeSession($backendUserAuthentication);
            throw new UserNotFoundException(
                'MCP: The backend is locked to administrators by [BE][adminOnly], request was refused',
                1756800312
            );
        }
    }

    /**
     * An api key alone is not enough: MCP is opened to editors by their backend group, so an editor does not get
     * it just because somebody created a key. check() answers true for administrators.
     *
     * @throws UserNotFoundException
     */
    protected function assertMcpAccess(BackendUserAuthentication $backendUserAuthentication): void
    {
        $permission = self::PERMISSION_OPTIONS . ':' . self::PERMISSION_ACCESS;
        if ($backendUserAuthentication->check('custom_options', $permission) === false) {
            $userIdentifier = (int)($backendUserAuthentication->user['uid'] ?? 0);
            $this->removeSession($backendUserAuthentication);
            throw new UserNotFoundException(
                'MCP: Backend user ' . $userIdentifier . ' has a credential, but none of its groups allows MCP'
                . ' (custom option "' . $permission . '"), request was refused',
                1756800316
            );
        }
    }

    /**
     * Not initializeBackendLogin(): its AfterUserLoggedInEvent would send a login notification mail per request
     */
    protected function initializeBackendUserWithoutLogin(BackendUserAuthentication $backendUserAuthentication): void
    {
        $backendUserAuthentication->fetchGroupData();
        $backendUserAuthentication->backendSetUC();
    }

    public function removeSession(BackendUserAuthentication $backendUserAuthentication): void
    {
        try {
            $backendUserAuthentication->logoff();
        } catch (Throwable $exception) {
            $this->logger->warning('MCP: Session of the request could not be removed: ' . $exception->getMessage());
        }
    }

    protected function setBackendUserAspect(BackendUserAuthentication $backendUserAuthentication): void
    {
        $this->context->setAspect('backend.user', new UserAspect($backendUserAuthentication));
        // The workspace of the user is -99 until fetchGroupData() has run, which is the second call of this method
        $this->context->setAspect('workspace', new WorkspaceAspect(max(0, $backendUserAuthentication->workspace)));
    }
}
