<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\TcaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reads records of any table of the installation. Pages and content elements have their own repositories,
 * because they carry the tree and the column logic; everything else is plain records on a page.
 */
readonly class RecordRepository
{
    private const DEFAULT_LIMIT = 50;
    private const MAXIMUM_LIMIT = 500;

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaService $tcaService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function findByUid(string $table, int $uid): ?array
    {
        $queryBuilder = $this->getQueryBuilder($table);

        $record = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $record === false ? null : $this->tcaService->cleanUpRecord($table, $record);
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function findByPid(string $table, int $pid, int $limit = self::DEFAULT_LIMIT): array
    {
        $queryBuilder = $this->getQueryBuilder($table);

        $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)))
            ->setMaxResults($this->getLimit($limit));

        foreach ($this->getOrderingFields($table) as $orderingField) {
            $queryBuilder->addOrderBy($orderingField);
        }

        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            fn(array $record): array => $this->tcaService->cleanUpRecord($table, $record),
            $records
        );
    }

    /**
     * Uid of the last record on a page in sorting order, 0 if the page holds no record of this table or if the
     * table is not sortable at all
     *
     * @throws Exception
     */
    public function findLastUidOnPid(string $table, int $pid): int
    {
        $sortingField = $this->tcaService->getSortingField($table);
        if ($sortingField === null) {
            return 0;
        }

        $queryBuilder = $this->getQueryBuilder($table);

        $uid = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)))
            ->orderBy($sortingField, 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? 0 : (int)$uid;
    }

    /**
     * @return string[]
     */
    private function getOrderingFields(string $table): array
    {
        $sortingField = $this->tcaService->getSortingField($table);
        return $sortingField === null ? ['uid'] : [$sortingField, 'uid'];
    }

    private function getLimit(int $limit): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAXIMUM_LIMIT);
    }

    private function getQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        return $queryBuilder;
    }
}
