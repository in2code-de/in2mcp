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
 * Authenticates a backend user by api key, but only for requests that were marked as MCP request by the
 * McpServer middleware. Every other request is left to the regular authentication services of TYPO3.
 */
#[Autoconfigure(public: true)]
class ApiKeyAuthenticationService extends AbstractAuthenticationService
{
    public const MCP_REQUEST_ATTRIBUTE = 'in2mcp.mcp';

    /**
     * Header names that may carry the api key. "Authorization" is the standard, "X-Api-Key" and "Api-Key" are
     * fallbacks for clients that can not set an authorization header and for webserver configurations that do
     * not pass it to php (e.g. apache with mod_proxy_fcgi without "CGIPassAuth On").
     */
    protected const API_KEY_HEADERS = ['X-Api-Key', 'Api-Key'];

    protected const AUTHORIZATION_HEADER = 'Authorization';
    protected const AUTHORIZATION_SCHEME = 'Bearer';

    protected const AUTHENTICATION_FAILED = 0;
    protected const AUTHENTICATION_NOT_RESPONSIBLE = 100;
    protected const AUTHENTICATION_SUCCESSFUL = 200;

    private array $backendUsersByApiKey = [];

    public function __construct(private readonly BackendUserRepository $backendUserRepository)
    {
    }

    public function getUser(): ?array
    {
        if ($this->isMcpRequest() === false) {
            return null;
        }

        try {
            return $this->findBackendUserByApiKey($this->getApiKey());
        } catch (InvalidArgumentException $exception) {
            $this->logger->warning('MCP: Authentication failed: ' . $exception->getMessage());
            return null;
        }
    }

    public function authUser(array $user): int
    {
        if ($this->isMcpRequest() === false) {
            return self::AUTHENTICATION_NOT_RESPONSIBLE;
        }

        try {
            $authenticatedUser = $this->findBackendUserByApiKey($this->getApiKey());
            $this->assertValidUser($authenticatedUser, $user);
        } catch (InvalidArgumentException | WrongUserException | UserNotFoundException $exception) {
            $this->logger->warning('MCP: Authentication failed: ' . $exception->getMessage());
            return self::AUTHENTICATION_FAILED;
        }

        $this->logger->info(
            'MCP: Backend user "' . ($authenticatedUser['username'] ?? '') . '" authenticated by api key'
        );
        return self::AUTHENTICATION_SUCCESSFUL;
    }

    protected function getApiKey(): string
    {
        $request = $this->authInfo['request'] ?? null;
        if (($request instanceof ServerRequestInterface) === false) {
            return '';
        }

        $authorization = trim($request->getHeaderLine(self::AUTHORIZATION_HEADER));
        if (stripos($authorization, self::AUTHORIZATION_SCHEME . ' ') === 0) {
            return trim(substr($authorization, strlen(self::AUTHORIZATION_SCHEME) + 1));
        }

        foreach (self::API_KEY_HEADERS as $header) {
            $apiKey = trim($request->getHeaderLine($header));
            if ($apiKey !== '') {
                return $apiKey;
            }
        }

        return '';
    }

    private function findBackendUserByApiKey(string $apiKey): ?array
    {
        if (array_key_exists($apiKey, $this->backendUsersByApiKey) === false) {
            $this->backendUsersByApiKey[$apiKey] = $this->backendUserRepository->findByApiKey($apiKey);
        }

        return $this->backendUsersByApiKey[$apiKey];
    }

    private function isMcpRequest(): bool
    {
        $request = $this->authInfo['request'] ?? null;
        if (($request instanceof ServerRequestInterface) === false) {
            return false;
        }

        return $request->getAttribute(self::MCP_REQUEST_ATTRIBUTE, false) === true;
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
