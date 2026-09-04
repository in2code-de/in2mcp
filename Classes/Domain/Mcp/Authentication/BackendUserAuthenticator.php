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
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A MCP client has no backend session, so the backend user of the api key is initialized exactly like the backend
 * does it for a regular request. Every read and write of the tools then runs with the permissions of that user.
 */
class BackendUserAuthenticator
{
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
        // The middleware has removed every cookie of the request, so a request without an api key can not be
        // authenticated at all and never has to reach the authentication of the core.
        $authenticationContext = AuthenticationContext::fromRequestAttribute($request);
        if ($authenticationContext === null || $authenticationContext->hasApiKey() === false) {
            throw new UserNotFoundException('MCP: Request does not provide an api key', 1756800304);
        }

        // Authentication services are only asked for a user if a login form was submitted or if this option is
        // set. It is enabled for the current MCP request only.
        $GLOBALS['TYPO3_CONF_VARS']['SVCONF']['auth']['setup']['BE_fetchUserIfNoSession'] = true;

        $backendUserAuthentication = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        try {
            $backendUserAuthentication->start($request);
        } catch (MfaRequiredException) {
            // The api key itself is the credential of the client, so a multi factor authentication (which a
            // client without a browser can not solve) is skipped for MCP requests.
        }

        if (isset($backendUserAuthentication->user['uid']) === false) {
            throw new UserNotFoundException('MCP: Request could not be authenticated', 1756800300);
        }

        $GLOBALS['BE_USER'] = $backendUserAuthentication;
        $this->setBackendUserAspect($backendUserAuthentication);
        $backendUserAuthentication->initializeBackendLogin($request);
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUserAuthentication);
        $this->setBackendUserAspect($backendUserAuthentication);

        return $backendUserAuthentication;
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
    }
}
