<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Finds the backend user that belongs to a given MCP api key. The keys are stored as salted hashes, so every
 * candidate has to be checked individually.
 */
readonly class BackendUserRepository
{
    public const API_KEY_FIELD = 'in2mcp_apikey';

    private const TABLE_NAME = 'be_users';

    public function __construct(
        private ConnectionPool $connectionPool,
        private PasswordHashFactory $passwordHashFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function findByApiKey(string $apiKey): ?array
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('MCP api key must not be empty', 1756800100);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);

        try {
            $backendUsersWithApiKey = $queryBuilder
                ->select('*')
                ->from(self::TABLE_NAME)
                ->where(
                    $queryBuilder->expr()->neq(
                        self::API_KEY_FIELD,
                        $queryBuilder->createNamedParameter('')
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Exception $exception) {
            $this->logger->error('MCP: Backend users could not be read: ' . $exception->getMessage());
            return null;
        }

        foreach ($backendUsersWithApiKey as $backendUser) {
            if ($this->isApiKeyOfBackendUser($apiKey, (string)($backendUser[self::API_KEY_FIELD] ?? ''))) {
                return $backendUser;
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    public function findByUidOrUsername(string $identifier): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $constraint = MathUtility::canBeInterpretedAsInteger($identifier)
            ? $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter((int)$identifier, Connection::PARAM_INT))
            : $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($identifier));

        $backendUser = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where($constraint)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $backendUser === false ? null : $backendUser;
    }

    /**
     * @throws Exception
     */
    public function updateApiKey(int $backendUserUid, string $hashedApiKey): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_NAME)->update(
            self::TABLE_NAME,
            [self::API_KEY_FIELD => $hashedApiKey],
            ['uid' => $backendUserUid]
        );
    }

    private function isApiKeyOfBackendUser(string $apiKey, string $hashedApiKey): bool
    {
        if ($hashedApiKey === '') {
            return false;
        }

        try {
            return $this->passwordHashFactory->get($hashedApiKey, 'BE')->checkPassword($apiKey, $hashedApiKey);
        } catch (InvalidPasswordHashException $exception) {
            $this->logger->warning('MCP: Api key with an unsupported hash is ignored: ' . $exception->getMessage());
            return false;
        }
    }
}
