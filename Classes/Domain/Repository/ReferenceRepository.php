<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\WorkspaceService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads the reference index of the core. The table itself stays denied for every tool, this repository only
 * answers which records point to a given one - the tool decides which of them the user may see.
 */
readonly class ReferenceRepository
{
    private const TABLE_NAME = 'sys_refindex';

    public function __construct(
        private ConnectionPool $connectionPool,
        private WorkspaceService $workspaceService,
    ) {
    }

    /**
     * @return list<array{tablename: string, recuid: int, field: string, flexpointer: string, softref_key: string}>
     * @throws Exception
     */
    public function findReferencesTo(string $table, int $uid, int $limit, int $offset): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll();
        $workspaceIds = array_values(array_unique([
            WorkspaceService::LIVE_WORKSPACE,
            $this->workspaceService->getWorkspaceId(),
        ]));

        $rows = $queryBuilder
            ->select('tablename', 'recuid', 'field', 'flexpointer', 'softref_key')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('ref_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in(
                    'workspace',
                    $queryBuilder->createNamedParameter($workspaceIds, Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('tablename')
            ->addOrderBy('recuid')
            ->addOrderBy('field')
            ->addOrderBy('hash')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'tablename' => (string)$row['tablename'],
                'recuid' => (int)$row['recuid'],
                'field' => (string)$row['field'],
                'flexpointer' => (string)$row['flexpointer'],
                'softref_key' => (string)$row['softref_key'],
            ],
            $rows
        );
    }
}
