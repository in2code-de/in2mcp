<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;

/**
 * Reads tables that are denied to every MCP user through the generic record tools. Only the fields named here
 * leave this class - never a password, an api key hash, an email address or an MFA secret.
 */
readonly class BackendUserActivityRepository
{
    private const USER_TABLE = 'be_users';
    private const HISTORY_TABLE = 'sys_history';
    private const USER_FIELDS = ['uid', 'username', 'realName', 'admin', 'disable', 'lastlogin', 'mfa'];
    private const HISTORY_FIELDS = ['uid', 'tstamp', 'actiontype', 'userid', 'tablename', 'recuid', 'history_data'];
    private const CLI_USER_PREFIX = '_cli_';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    public function findUsers(): array
    {
        $queryBuilder = $this->getQueryBuilder(self::USER_TABLE);
        $queryBuilder->getRestrictions()->add(new DeletedRestriction());
        return $queryBuilder
            ->select(...self::USER_FIELDS)
            ->from(self::USER_TABLE)
            ->where(
                $queryBuilder->expr()->notLike(
                    'username',
                    $queryBuilder->createNamedParameter(
                        $queryBuilder->escapeLikeWildcards(self::CLI_USER_PREFIX) . '%'
                    )
                )
            )
            ->orderBy('lastlogin', 'DESC')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Deleted users are included, because the history keeps their changes
     *
     * @param list<int> $userUids
     * @return array<int, string> backend user uid => username
     * @throws Exception
     */
    public function findUsernames(array $userUids): array
    {
        if ($userUids === []) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(self::USER_TABLE);
        $users = $queryBuilder
            ->select('uid', 'username')
            ->from(self::USER_TABLE)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($userUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $usernames = [];
        foreach ($users as $user) {
            $usernames[(int)$user['uid']] = (string)$user['username'];
        }
        return $usernames;
    }

    /**
     * @return array<int, int> backend user uid => timestamp of the last change in the record history
     * @throws Exception
     */
    public function findLastChangeTimesByUser(): array
    {
        $queryBuilder = $this->getQueryBuilder(self::HISTORY_TABLE);
        $rows = $queryBuilder
            ->select('userid')
            ->addSelectLiteral(
                'MAX(' . $queryBuilder->quoteIdentifier('tstamp') . ') AS ' . $queryBuilder->quoteIdentifier('changed')
            )
            ->from(self::HISTORY_TABLE)
            ->where($this->getBackendUserConstraint($queryBuilder))
            ->groupBy('userid')
            ->executeQuery()
            ->fetchAllAssociative();

        $changeTimes = [];
        foreach ($rows as $row) {
            $changeTimes[(int)$row['userid']] = (int)$row['changed'];
        }
        return $changeTimes;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    public function findRecentChanges(int $userUid, string $table, int $limit): array
    {
        $queryBuilder = $this->getQueryBuilder(self::HISTORY_TABLE);
        $queryBuilder
            ->select(...self::HISTORY_FIELDS)
            ->from(self::HISTORY_TABLE)
            ->where($this->getBackendUserConstraint($queryBuilder))
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit);

        if ($userUid > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'userid',
                    $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT)
                )
            );
        }
        if ($table !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table))
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    private function getBackendUserConstraint(QueryBuilder $queryBuilder): string
    {
        return (string)$queryBuilder->expr()->and(
            $queryBuilder->expr()->eq(
                'usertype',
                $queryBuilder->createNamedParameter(RecordHistoryStore::USER_BACKEND)
            ),
            $queryBuilder->expr()->gt('userid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
        );
    }

    private function getQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
