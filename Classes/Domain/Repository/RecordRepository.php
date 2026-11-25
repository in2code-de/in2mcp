<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\EmptyFieldService;
use In2code\In2mcp\Domain\Service\InlineRelationService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Domain\Service\WorkspaceService;
use In2code\In2mcp\Exception\ToolExecutionException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Reads records of any table of the installation. Pages and content elements have their own repositories,
 * because they carry the tree and the column logic; everything else is plain records on a page.
 */
readonly class RecordRepository
{
    public const LOCALIZATION_STATE_FIELD = 'l10n_state';
    private const DEFAULT_LIMIT = 50;
    private const MAXIMUM_LIMIT = 500;
    private const MAXIMUM_FILTER_VALUES = 1000;

    public function __construct(
        private RecordQueryBuilderFactory $recordQueryBuilderFactory,
        private TcaService $tcaService,
        private InlineRelationService $inlineRelationService,
        private WorkspaceService $workspaceService,
        private EmptyFieldService $emptyFieldService,
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

        $record = $record === false ? null : $this->workspaceService->overlayRecord($table, $record);
        if ($record === null) {
            return null;
        }

        return $this->inlineRelationService->resolveRecord(
            $table,
            $this->tcaService->cleanUpRecord($table, $record)
        );
    }

    /**
     * The default language record a translation belongs to, and the localization state of its synchronized
     * fields - both as stored, because a cleaned up record leaves the state out. Null for a missing record.
     *
     * @return array{translationParent: int, localizationState: array<string, string>}|null
     * @throws Exception
     */
    public function findTranslationState(string $table, int $uid, string $translationParentField): ?array
    {
        $queryBuilder = $this->getQueryBuilder($table);
        $record = $queryBuilder
            ->select('uid', $translationParentField, self::LOCALIZATION_STATE_FIELD)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        $record = $record === false ? null : $this->workspaceService->overlayRecord($table, $record);

        return $record === null ? null : [
            'translationParent' => (int)($record[$translationParentField] ?? 0),
            'localizationState' => (array)json_decode((string)($record[self::LOCALIZATION_STATE_FIELD] ?? ''), true),
        ];
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
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        $queryBuilder = $this->getQueryBuilder($table);
        $queryBuilder
            ->select('*')
            ->from($table)
            ->setMaxResults($this->getLimit($limit))
            ->setFirstResult($offset);

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
            $queryBuilder->andWhere($this->createFilterCondition($queryBuilder, $table, (string)$fieldName, $value));
        }

        foreach ($this->getOrderingFields($table) as $orderingField) {
            $queryBuilder->addOrderBy($orderingField);
        }

        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            fn (array $record): array => $this->inlineRelationService->resolveRecord(
                $table,
                $this->tcaService->cleanUpRecord($table, $record)
            ),
            $this->workspaceService->overlayRecords($table, $records)
        );
    }

    /**
     * @throws ToolExecutionException
     */
    private function createFilterCondition(
        QueryBuilder $queryBuilder,
        string $table,
        string $fieldName,
        mixed $value
    ): string|CompositeExpression {
        $condition = $queryBuilder->expr()->eq($fieldName, $queryBuilder->createNamedParameter($value));
        if (is_array($value)) {
            $condition = $this->createListCondition($queryBuilder, $fieldName, $value);
        }
        if ($value === null) {
            $emptyValue = $this->emptyFieldService->getEmptyValue($table, $fieldName);
            $condition = $queryBuilder->expr()->or(
                $queryBuilder->expr()->isNull($fieldName),
                $queryBuilder->expr()->eq(
                    $fieldName,
                    $queryBuilder->createNamedParameter(
                        $emptyValue,
                        is_int($emptyValue) ? Connection::PARAM_INT : Connection::PARAM_STR
                    )
                )
            );
        }
        return $condition;
    }

    /**
     * @param array<mixed> $values
     * @throws ToolExecutionException
     */
    private function createListCondition(QueryBuilder $queryBuilder, string $fieldName, array $values): string
    {
        $isValidList = $values !== []
            && array_is_list($values)
            && count($values) <= self::MAXIMUM_FILTER_VALUES
            && array_filter($values, static fn (mixed $value): bool => is_scalar($value)) === $values;
        if ($isValidList === false) {
            throw new ToolExecutionException(
                'The filter of "' . $fieldName . '" has to be a single value or a list of 1 to '
                . self::MAXIMUM_FILTER_VALUES . ' numbers or texts, for example {"' . $fieldName . '": [12, 15]}',
                1764079200
            );
        }

        $isNumberList = array_filter($values, 'is_int') === $values;
        return $queryBuilder->expr()->in(
            $fieldName,
            $queryBuilder->createNamedParameter(
                $values,
                $isNumberList ? Connection::PARAM_INT_ARRAY : Connection::PARAM_STR_ARRAY
            )
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

        if ($this->tcaService->isFieldReadable($table, $fieldName) === false) {
            throw new ToolExecutionException(
                'The backend user is not allowed to read the field "' . $fieldName . '" of "' . $table . '", so'
                . ' it can not be used as a filter',
                1756801702
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

    public function getLimit(int $limit): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAXIMUM_LIMIT);
    }

    private function getQueryBuilder(string $table): QueryBuilder
    {
        return $this->recordQueryBuilderFactory->create($table);
    }
}
