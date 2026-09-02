<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\InlineRelationService;
use In2code\In2mcp\Exception\ToolExecutionException;
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
        private InlineRelationService $inlineRelationService,
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

        if ($record === false) {
            return null;
        }

        return $this->inlineRelationService->resolveRecord(
            $table,
            $this->tcaService->cleanUpRecord($table, $record)
        );
    }

    /**
     * Records of a table, optionally restricted to a page, to field values and to a language.
     *
     * A pid of null searches the whole installation, which is what a client needs to find a record whose page
     * it does not know - looking for it by trying one page after the other is not a search.
     *
     * @param array<string, mixed> $filters Field name to value, all of them have to match
     * @return array<int, array<string, mixed>>
     * @throws Exception
     * @throws ToolExecutionException
     */
    public function find(
        string $table,
        ?int $pid = null,
        array $filters = [],
        ?int $languageId = null,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $queryBuilder = $this->getQueryBuilder($table);
        $queryBuilder
            ->select('*')
            ->from($table)
            ->setMaxResults($this->getLimit($limit));

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT))
            );
        }

        $languageField = $this->tcaService->getLanguageField($table);
        if ($languageId !== null && $languageField !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            );
        }

        foreach ($filters as $fieldName => $value) {
            $this->assertFilterableField($table, (string)$fieldName);
            // The name is whitelisted against the TCA above and quoted by the QueryBuilder itself; quoting it
            // here as well produces a column name with backticks in it.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    (string)$fieldName,
                    $queryBuilder->createNamedParameter($value)
                )
            );
        }

        foreach ($this->getOrderingFields($table) as $orderingField) {
            $queryBuilder->addOrderBy($orderingField);
        }

        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            fn(array $record): array => $this->inlineRelationService->resolveRecord(
                $table,
                $this->tcaService->cleanUpRecord($table, $record)
            ),
            $records
        );
    }

    /**
     * A field name goes into the query as an identifier and can not be a parameter, so only names that really
     * exist in this table are accepted.
     *
     * @throws ToolExecutionException
     */
    private function assertFilterableField(string $table, string $fieldName): void
    {
        if (in_array($fieldName, ['uid', 'pid'], true)) {
            return;
        }

        if ($this->tcaService->isFieldOfTable($table, $fieldName) === false) {
            throw new ToolExecutionException(
                'There is no field "' . $fieldName . '" in "' . $table . '" to filter by. Call "get_schema"'
                . ' with the table to see its fields.',
                1756801700
            );
        }
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
