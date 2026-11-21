<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\AccessScope;
use In2code\In2mcp\Domain\Model\OAuthGrant;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class OAuthGrantRepository
{
    public const TABLE_NAME = 'tx_in2mcp_oauth_grant';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * @param array<string, int|string> $values
     * @throws Exception
     */
    public function insert(array $values): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
        $connection->insert(self::TABLE_NAME, $values);
        return (int)$connection->lastInsertId();
    }

    /**
     * @throws Exception
     */
    public function findByValidAccessTokenHash(string $accessTokenHash, int $now): ?OAuthGrant
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'access_token_hash',
                    $queryBuilder->createNamedParameter($accessTokenHash)
                ),
                $queryBuilder->expr()->gt(
                    'access_token_expires',
                    $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : OAuthGrant::fromRow($row);
    }

    /**
     * A grant counts as long as its refresh token can still be used, the access token expires within the hour
     *
     * @throws Exception
     */
    public function hasValidGrantWithWriteAccess(int $backendUserIdentifier, int $now): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $scopes = $queryBuilder
            ->select('scope')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($backendUserIdentifier, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->gt(
                    'refresh_token_expires',
                    $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $writeScopes = array_filter(
            $scopes,
            static fn (mixed $scope): bool => AccessScope::isReadOnly(AccessScope::fromScopeString((string)$scope))
                === false
        );
        return $writeScopes !== [];
    }
}
