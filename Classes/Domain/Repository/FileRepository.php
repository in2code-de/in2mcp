<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reads files of the file abstraction layer and the references that point at them. A client cannot upload
 * files through MCP, it can only connect files that already exist in this installation.
 */
readonly class FileRepository
{
    public const TABLE_NAME = 'sys_file';
    public const REFERENCE_TABLE_NAME = 'sys_file_reference';
    private const METADATA_TABLE_NAME = 'sys_file_metadata';
    private const REFERENCE_INDEX_TABLE_NAME = 'sys_refindex';

    private const DEFAULT_LIMIT = 25;
    private const MAXIMUM_LIMIT = 200;

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * Searches files by a part of their name or path. Metadata is joined in, because the title and the
     * alternative text tell a client much better whether a file fits than the file name alone.
     *
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    public function findFiles(
        string $searchTerm,
        string $fileExtension = '',
        int $limit = self::DEFAULT_LIMIT,
        ?array $fileMounts = null
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select(
                'f.uid',
                'f.name',
                'f.identifier',
                'f.extension',
                'f.mime_type',
                'f.size',
                'm.title',
                'm.alternative',
                'm.description'
            )
            ->from(self::TABLE_NAME, 'f')
            ->leftJoin(
                'f',
                'sys_file_metadata',
                'm',
                $queryBuilder->expr()->eq('m.file', $queryBuilder->quoteIdentifier('f.uid'))
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'f.missing',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('f.name')
            ->setMaxResults($this->getLimit($limit));

        if ($searchTerm !== '') {
            $pattern = '%' . $queryBuilder->escapeLikeWildcards($searchTerm) . '%';
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('f.name', $queryBuilder->createNamedParameter($pattern)),
                    $queryBuilder->expr()->like('f.identifier', $queryBuilder->createNamedParameter($pattern)),
                    $queryBuilder->expr()->like('m.title', $queryBuilder->createNamedParameter($pattern))
                )
            );
        }

        if ($fileExtension !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'f.extension',
                    $queryBuilder->createNamedParameter(ltrim(strtolower($fileExtension), '.'))
                )
            );
        }

        $this->restrictToFileMounts($queryBuilder, $fileMounts);

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<int, array{storage: int, path: string}>|null $fileMounts Null means no restriction
     * @throws Exception
     */
    public function findFileByUid(int $uid, ?array $fileMounts = null): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('uid', 'name', 'identifier', 'extension', 'mime_type', 'size', 'storage')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1);

        $this->restrictToFileMounts($queryBuilder, $fileMounts, '');

        $file = $queryBuilder->executeQuery()->fetchAssociative();

        return $file === false ? null : $file;
    }

    /**
     * Restricts a file query to the file mounts of the backend user. A null list means no restriction, which is
     * the case for administrators; an empty list means no mount at all and therefore no file.
     *
     * @param array<int, array{storage: int, path: string}>|null $fileMounts
     */
    private function restrictToFileMounts(QueryBuilder $queryBuilder, ?array $fileMounts, string $alias = 'f.'): void
    {
        if ($fileMounts === null) {
            return;
        }

        if ($fileMounts === []) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }

        $constraints = [];
        foreach ($fileMounts as $fileMount) {
            $constraints[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq(
                    $alias . 'storage',
                    $queryBuilder->createNamedParameter($fileMount['storage'], Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->like(
                    $alias . 'identifier',
                    $queryBuilder->createNamedParameter(
                        $queryBuilder->escapeLikeWildcards($fileMount['path']) . '%'
                    )
                )
            );
        }

        $queryBuilder->andWhere($queryBuilder->expr()->or(...$constraints));
    }

    /**
     * Uids of the file references of one field of one record, in the order an editor sees them
     *
     * @return int[]
     * @throws Exception
     */
    public function findReferenceUids(string $table, int $uid, string $fieldName): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $references = $queryBuilder
            ->select('uid')
            ->from(self::REFERENCE_TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter($table)
                ),
                $queryBuilder->expr()->eq(
                    'uid_foreign',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'fieldname',
                    $queryBuilder->createNamedParameter($fieldName)
                )
            )
            ->orderBy('sorting_foreign')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $references);
    }

    /**
     * Records that still use a file, checked the same way as the filelist module does before a deletion: the
     * reference index without the metadata of the file itself. A file reference is resolved to the record it
     * belongs to and keeps its own uid, so a client can remove it. A reference or a record that does not exist
     * anymore is no usage.
     *
     * @return array<int, array{table: string, uid: int, field: string, reference: int}>
     * @throws Exception
     */
    public function findUsages(int $fileUid): array
    {
        $usages = [];
        foreach ($this->findReferenceIndexEntries($fileUid) as $entry) {
            $usage = $entry['tablename'] === self::REFERENCE_TABLE_NAME
                ? $this->findUsageOfFileReference((int)$entry['recuid'])
                : [
                    'table' => (string)$entry['tablename'],
                    'uid' => (int)$entry['recuid'],
                    'field' => (string)$entry['field'],
                    'reference' => 0,
                ];

            if ($usage !== null && $this->isRecordExisting($usage['table'], $usage['uid'])) {
                $usages[] = $usage;
            }
        }
        return $usages;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws Exception
     */
    private function findReferenceIndexEntries(int $fileUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_INDEX_TABLE_NAME);

        return $queryBuilder
            ->select('tablename', 'recuid', 'field')
            ->from(self::REFERENCE_INDEX_TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter(self::TABLE_NAME)),
                $queryBuilder->expr()->eq(
                    'ref_uid',
                    $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->neq('tablename', $queryBuilder->createNamedParameter(self::METADATA_TABLE_NAME))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array{table: string, uid: int, field: string, reference: int}|null
     * @throws Exception
     */
    private function findUsageOfFileReference(int $referenceUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $reference = $queryBuilder
            ->select('tablenames', 'uid_foreign', 'fieldname')
            ->from(self::REFERENCE_TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($referenceUid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        return $reference === false ? null : [
            'table' => (string)$reference['tablenames'],
            'uid' => (int)$reference['uid_foreign'],
            'field' => (string)$reference['fieldname'],
            'reference' => $referenceUid,
        ];
    }

    /**
     * @throws Exception
     */
    private function isRecordExisting(string $table, int $uid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->count('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne() > 0;
    }

    private function getLimit(int $limit): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAXIMUM_LIMIT);
    }
}
