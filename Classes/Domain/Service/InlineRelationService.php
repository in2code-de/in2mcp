<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Exception\ToolExecutionException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Translates between the two faces of an inline relation.
 *
 * The database column of such a field holds the number of children, the DataHandler expects the list of their
 * uids. A client that reads "2" and writes "3" does not add a child, it attaches the record with uid 3 to this
 * parent and steals it from wherever it belonged. So a read replaces the number with the real list, and a write
 * is refused unless it is a list of children that may actually be attached.
 */
class InlineRelationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaService $tcaService,
    ) {
    }

    /**
     * Replaces the child counter of every inline field with the list of the child uids
     *
     * @throws Exception
     */
    public function resolveRecord(string $table, array $record): array
    {
        $uid = (int)($record['uid'] ?? 0);
        if ($uid === 0) {
            return $record;
        }

        foreach ($this->tcaService->getInlineRelations($table) as $fieldName => $relation) {
            if (array_key_exists($fieldName, $record) === false) {
                continue;
            }

            $childUids = $this->findChildUids($relation, $uid);
            $record[$fieldName] = implode(',', $childUids);
        }

        return $record;
    }

    /**
     * Uids of the children of one inline field, in the order an editor sees them
     *
     * @param array{foreignTable: string, foreignField: string, foreignSortby: string} $relation
     * @return int[]
     * @throws Exception
     */
    public function findChildUids(array $relation, int $parentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($relation['foreignTable']);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $queryBuilder
            ->select('uid')
            ->from($relation['foreignTable'])
            ->where(
                $queryBuilder->expr()->eq(
                    $relation['foreignField'],
                    $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)
                )
            );

        if ($relation['foreignSortby'] !== '') {
            $queryBuilder->orderBy($relation['foreignSortby']);
        }
        $queryBuilder->addOrderBy('uid');

        return array_map('intval', $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * Refuses a value for an inline field that is not a list of children this parent may hold. Without this a
     * client that writes the number of children silently attaches somebody else's records.
     *
     * @param array{foreignTable: string, foreignField: string, foreignSortby: string} $relation
     * @throws Exception
     * @throws ToolExecutionException
     */
    public function assertWritableValue(string $table, string $fieldName, mixed $value, int $parentUid): void
    {
        $givenUids = $this->getUidList($value);
        if ($givenUids === []) {
            return;
        }

        $relation = $this->tcaService->getInlineRelation($table, $fieldName);
        if ($relation === null) {
            return;
        }

        foreach ($givenUids as $childUid) {
            $owner = $this->findOwner($relation, $childUid);

            if ($owner === null) {
                throw new ToolExecutionException(
                    'The field "' . $fieldName . '" of "' . $table . '" is a list of the uids of its child'
                    . ' records in "' . $relation['foreignTable'] . '", not a number of children. There is no'
                    . ' record with uid ' . $childUid . ' in that table. Use "add_child_record" to create a'
                    . ' child, it keeps the list and the counter correct.',
                    1756801500
                );
            }

            if ($owner !== 0 && $owner !== $parentUid) {
                throw new ToolExecutionException(
                    'Record ' . $childUid . ' of "' . $relation['foreignTable'] . '" belongs to '
                    . $relation['foreignField'] . ' ' . $owner . ' and would be taken away from it. The field'
                    . ' "' . $fieldName . '" is a list of child uids, not a number of children. Use'
                    . ' "add_child_record" to create a child, or pass the uids this parent already has.',
                    1756801504
                );
            }
        }
    }

    /**
     * @param array{foreignTable: string, foreignField: string, foreignSortby: string} $relation
     * @throws Exception
     */
    private function findOwner(array $relation, int $childUid): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($relation['foreignTable']);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $owner = $queryBuilder
            ->select($relation['foreignField'])
            ->from($relation['foreignTable'])
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($childUid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $owner === false ? null : (int)$owner;
    }

    /**
     * @return int[]
     */
    private function getUidList(mixed $value): array
    {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        $uids = [];
        foreach (explode(',', (string)$value) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '0') {
                continue;
            }
            $uids[] = (int)$part;
        }

        return $uids;
    }
}
