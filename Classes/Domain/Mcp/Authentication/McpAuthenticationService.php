<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Authentication;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\OAuthGrant;
use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Domain\Repository\OAuthGrantRepository;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\EndpointUrlService;
use In2code\In2mcp\Domain\Service\OAuthTokenService;
use In2code\In2mcp\Exception\UserNotFoundException;
use In2code\In2mcp\Exception\WrongUserException;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;

/**
 * Authenticates a backend user by api key or OAuth access token, but only for requests that carry the
 * authentication context of a MCP middleware. Every other request is left to the regular authentication services.
 */
#[Autoconfigure(public: true)]
class McpAuthenticationService extends AbstractAuthenticationService
{
    protected const AUTHENTICATION_FAILED = 0;
    protected const AUTHENTICATION_NOT_RESPONSIBLE = 100;
    protected const AUTHENTICATION_SUCCESSFUL = 200;

    private array $backendUsersByApiKey = [];

    /**
     * @var array<string, OAuthGrant|null>
     */
    private array $grantsByAccessToken = [];

    public function __construct(
        private readonly BackendUserRepository $backendUserRepository,
        private readonly OAuthGrantRepository $grantRepository,
        private readonly OAuthTokenService $tokenService,
        private readonly ConfigurationService $configurationService,
        private readonly EndpointUrlService $endpointUrlService,
    ) {
    }

    public function getUser(): ?array
    {
        $context = $this->getAuthenticationContext();
        if ($context === null) {
            return null;
        }

        try {
            return $this->findBackendUser($context);
        } catch (InvalidArgumentException | Exception $exception) {
            $this->logger->warning('MCP: Authentication failed: ' . $exception->getMessage());
            return null;
        }
    }

    public function authUser(array $user): int
    {
        $context = $this->getAuthenticationContext();
        if ($context === null) {
            return self::AUTHENTICATION_NOT_RESPONSIBLE;
        }

        try {
            $authenticatedUser = $this->findBackendUser($context);
            $this->assertValidUser($authenticatedUser, $user);
        } catch (InvalidArgumentException | Exception | WrongUserException | UserNotFoundException $exception) {
            $this->logger->warning('MCP: Authentication failed: ' . $exception->getMessage());
            return self::AUTHENTICATION_FAILED;
        }

        // The authenticator of the middleware verifies that the backend user it ends up with is exactly this one
        $context->setAuthenticatedUserIdentifier((int)$authenticatedUser['uid']);
        $context->setReadOnly($this->getGrant($context)?->isReadOnly() ?? false);

        $this->logger->info(
            'MCP: Backend user "' . ($authenticatedUser['username'] ?? '') . '" authenticated by '
            . $this->getCredentialName($context)
        );
        return self::AUTHENTICATION_SUCCESSFUL;
    }

    /**
     * @throws Exception
     */
    private function findBackendUser(AuthenticationContext $context): ?array
    {
        $uploadUserIdentifier = $context->getUploadUserIdentifier();
        if ($uploadUserIdentifier !== null) {
            return $this->findUploadUser($uploadUserIdentifier);
        }
        if ($context->isAccessToken()) {
            $grant = $this->getGrant($context);
            return $grant === null ? null : $this->backendUserRepository->findByUid($grant->getBackendUserIdentifier());
        }
        if ($this->configurationService->isApiKeyAuthenticationActivated() === false) {
            return null;
        }
        return $this->findBackendUserByApiKey($context->getCredential());
    }

    /**
     * An upload token is only as good as the access of the user who requested it: revoking the api key or the
     * last grant with write access also ends every upload token of that user
     *
     * @throws Exception
     */
    private function findUploadUser(int $userIdentifier): ?array
    {
        $backendUser = $this->configurationService->isApiKeyAuthenticationActivated()
            ? $this->backendUserRepository->findByUidWithApiKey($userIdentifier)
            : null;

        if ($backendUser === null
            && $this->configurationService->isOAuthActivated()
            && $this->grantRepository->hasValidGrantWithWriteAccess($userIdentifier, time())
        ) {
            $backendUser = $this->backendUserRepository->findByUid($userIdentifier);
        }
        return $backendUser;
    }

    /**
     * @throws Exception
     */
    private function getGrant(AuthenticationContext $context): ?OAuthGrant
    {
        $accessToken = $context->getCredential();
        if ($context->isAccessToken() === false || $this->configurationService->isOAuthActivated() === false) {
            return null;
        }

        if (array_key_exists($accessToken, $this->grantsByAccessToken) === false) {
            $this->grantsByAccessToken[$accessToken] = $this->tokenService->findGrantByAccessToken(
                $accessToken,
                $this->endpointUrlService->getMcpServerUrl($this->authInfo['request'])
            );
        }
        return $this->grantsByAccessToken[$accessToken];
    }

    private function findBackendUserByApiKey(string $apiKey): ?array
    {
        if (array_key_exists($apiKey, $this->backendUsersByApiKey) === false) {
            $this->backendUsersByApiKey[$apiKey] = $this->backendUserRepository->findByApiKey($apiKey);
        }

        return $this->backendUsersByApiKey[$apiKey];
    }

    private function getCredentialName(AuthenticationContext $context): string
    {
        if ($context->getUploadUserIdentifier() !== null) {
            return 'upload token';
        }
        return $context->isAccessToken() ? 'OAuth access token' : 'api key';
    }

    private function getAuthenticationContext(): ?AuthenticationContext
    {
        $request = $this->authInfo['request'] ?? null;
        if (($request instanceof ServerRequestInterface) === false) {
            return null;
        }

        return AuthenticationContext::fromRequestAttribute($request);
    }

    /**
     * @throws WrongUserException
     * @throws UserNotFoundException
     */
    private function assertValidUser(?array $credentialUser, array $userToAuthenticate): void
    {
        if ($credentialUser === null) {
            throw new UserNotFoundException('No backend user found for the given credential', 1756800200);
        }

        $credentialUid = (int)($credentialUser['uid']
            ?? throw new WrongUserException('No user found for the given credential', 1756800204));
        $userToAuthenticateUid = (int)($userToAuthenticate['uid']
            ?? throw new WrongUserException('No user found for the given backend user', 1756800208));

        if ($credentialUid !== $userToAuthenticateUid) {
            throw new WrongUserException('Credential does not belong to the given backend user', 1756800212);
        }
    }
}
