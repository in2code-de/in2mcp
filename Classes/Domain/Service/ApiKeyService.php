<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

/**
 * Creates and revokes the MCP api key of a backend user. The plain key is returned exactly once and only stored
 * as a hash, so it can never be read again from the installation.
 */
readonly class ApiKeyService
{
    private const KEY_BYTES = 96;

    public function __construct(
        private BackendUserRepository $backendUserRepository,
        private PasswordHashFactory $passwordHashFactory,
    ) {
    }

    /**
     * @return string The plain api key, which is not recoverable afterwards
     * @throws UserNotFoundException
     * @throws InvalidPasswordHashException
     * @throws Exception
     */
    public function createApiKey(string $backendUserIdentifier): string
    {
        $backendUser = $this->getBackendUser($backendUserIdentifier);
        $apiKey = $this->generateApiKey();

        $this->backendUserRepository->updateApiKey(
            (int)$backendUser['uid'],
            $this->passwordHashFactory->getDefaultHashInstance('BE')->getHashedPassword($apiKey)
        );

        return $apiKey;
    }

    /**
     * @throws UserNotFoundException
     * @throws Exception
     */
    public function revokeApiKey(string $backendUserIdentifier): void
    {
        $backendUser = $this->getBackendUser($backendUserIdentifier);
        $this->backendUserRepository->updateApiKey((int)$backendUser['uid'], '');
    }

    /**
     * A url safe alphabet without "+", "/" and "=" keeps the key free of characters that need quoting in a
     * shell, in a url or in a json configuration file.
     */
    private function generateApiKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::KEY_BYTES)), '+/', '-_'), '=');
    }

    /**
     * @throws UserNotFoundException
     * @throws Exception
     */
    public function getBackendUser(string $backendUserIdentifier): array
    {
        $backendUser = $this->backendUserRepository->findByUidOrUsername($backendUserIdentifier);
        if ($backendUser === null) {
            throw new UserNotFoundException(
                'No backend user found for "' . $backendUserIdentifier . '"',
                1756800600
            );
        }

        return $backendUser;
    }
}
