<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Authentication;

use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Exception\UserNotFoundException;
use In2code\In2mcp\Exception\WrongUserException;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;

/**
 * Authenticates a backend user by api key, but only for requests that carry the authentication context of the
 * McpServer middleware. Every other request is left to the regular authentication services of TYPO3.
 */
#[Autoconfigure(public: true)]
class ApiKeyAuthenticationService extends AbstractAuthenticationService
{
    protected const AUTHENTICATION_FAILED = 0;
    protected const AUTHENTICATION_NOT_RESPONSIBLE = 100;
    protected const AUTHENTICATION_SUCCESSFUL = 200;

    private array $backendUsersByApiKey = [];

    public function __construct(private readonly BackendUserRepository $backendUserRepository)
    {
    }

    public function getUser(): ?array
    {
        $context = $this->getAuthenticationContext();
        if ($context === null) {
            return null;
        }

        try {
            return $this->findBackendUserByApiKey($context->getApiKey());
        } catch (InvalidArgumentException $exception) {
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
            $authenticatedUser = $this->findBackendUserByApiKey($context->getApiKey());
            $this->assertValidUser($authenticatedUser, $user);
        } catch (InvalidArgumentException | WrongUserException | UserNotFoundException $exception) {
            $this->logger->warning('MCP: Authentication failed: ' . $exception->getMessage());
            return self::AUTHENTICATION_FAILED;
        }

        // The authenticator of the middleware verifies that the backend user it ends up with is exactly this one
        $context->setAuthenticatedUserIdentifier((int)$authenticatedUser['uid']);

        $this->logger->info(
            'MCP: Backend user "' . ($authenticatedUser['username'] ?? '') . '" authenticated by api key'
        );
        return self::AUTHENTICATION_SUCCESSFUL;
    }

    private function findBackendUserByApiKey(string $apiKey): ?array
    {
        if (array_key_exists($apiKey, $this->backendUsersByApiKey) === false) {
            $this->backendUsersByApiKey[$apiKey] = $this->backendUserRepository->findByApiKey($apiKey);
        }

        return $this->backendUsersByApiKey[$apiKey];
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
    private function assertValidUser(?array $apiKeyUser, array $userToAuthenticate): void
    {
        if ($apiKeyUser === null) {
            throw new UserNotFoundException('No backend user found for the given api key', 1756800200);
        }

        $apiKeyUid = (int)($apiKeyUser['uid']
            ?? throw new WrongUserException('No user found for the given api key', 1756800204));
        $userToAuthenticateUid = (int)($userToAuthenticate['uid']
            ?? throw new WrongUserException('No user found for the given backend user', 1756800208));

        if ($apiKeyUid !== $userToAuthenticateUid) {
            throw new WrongUserException('Api key does not belong to the given backend user', 1756800212);
        }
    }
}
