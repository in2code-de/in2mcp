<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

readonly class RecentChangeRepository
{
    private const PAGE_FIELDS = ['uid', 'pid', 'title', 'nav_title', 'slug', 'doktype', 'hidden'];
    private const CONTENT_FIELDS = ['uid', 'pid', 'CType', 'header', 'colPos', 'sys_language_uid', 'hidden', 'tstamp'];
    private const DEFAULT_LANGUAGE = 0;
    private const ALL_LANGUAGES = -1;

    public function __construct(
        private ConnectionPool $connectionPool,
        private BackendUserService $backendUserService,
    ) {
    }

    /**
     * Translations of a page are counted for the page in the default language
     *
     * @param list<int>|null $pageUids null means every page
     * @return array<int, int> page uid => timestamp of the last change of its properties
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findPageChangeTimes(?array $pageUids, int $limit): array
    {
        $queryBuilder = $this->getQueryBuilder(PageRepository::TABLE_NAME);
        $pageUidExpression = 'CASE WHEN ' . $queryBuilder->quoteIdentifier('sys_language_uid') . ' > 0'
            . ' THEN ' . $queryBuilder->quoteIdentifier('l10n_parent')
            . ' ELSE ' . $queryBuilder->quoteIdentifier('uid') . ' END';

        $queryBuilder
            ->selectLiteral(
                $pageUidExpression . ' AS ' . $queryBuilder->quoteIdentifier('page_uid'),
                'MAX(' . $queryBuilder->quoteIdentifier('tstamp') . ') AS ' . $queryBuilder->quoteIdentifier('changed')
            )
            ->from(PageRepository::TABLE_NAME)
            ->where($queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause()))
            ->groupBy('page_uid')
            ->orderBy('changed', 'DESC')
            ->setMaxResults($limit);

        if ($pageUids !== null) {
            $uidList = $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY);
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in('uid', $uidList),
                    $queryBuilder->expr()->in('l10n_parent', $uidList)
                )
            );
        }

        $allowedLanguageIds = $this->backendUserService->getAllowedLanguageIds();
        if ($allowedLanguageIds !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->in(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter($allowedLanguageIds, Connection::PARAM_INT_ARRAY)
                    )
                )
            );
        }

        return $this->toTimestampMap($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Content elements of every language are counted for the page they are stored on
     *
     * @param list<int>|null $pageUids null means every page
     * @return array<int, int> page uid => timestamp of the last change of one of its content elements
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findContentChangeTimes(?array $pageUids, int $limit): array
    {
        $queryBuilder = $this->getContentQueryBuilder($pageUids);
        $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('c.pid') . ' AS ' . $queryBuilder->quoteIdentifier('page_uid'),
                'MAX(' . $queryBuilder->quoteIdentifier('c.tstamp') . ')'
                    . ' AS ' . $queryBuilder->quoteIdentifier('changed')
            )
            ->groupBy('c.pid')
            ->orderBy('changed', 'DESC')
            ->setMaxResults($limit);

        return $this->toTimestampMap($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * @param list<int> $pageUids
     * @return array<int, array<string, mixed>> page uid => page
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findPagesByUids(array $pageUids): array
    {
        if ($pageUids === []) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(PageRepository::TABLE_NAME);
        $pages = $queryBuilder
            ->select(...self::PAGE_FIELDS)
            ->from(PageRepository::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause())
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_column($pages, null, 'uid');
    }

    /**
     * @param list<int>|null $pageUids null means every page
     * @return list<array<string, mixed>>
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findRecentContent(?array $pageUids, ?int $languageId, int $limit): array
    {
        $queryBuilder = $this->getContentQueryBuilder($pageUids);
        $queryBuilder
            ->select(...array_map(static fn(string $field): string => 'c.' . $field, self::CONTENT_FIELDS))
            ->addSelect('pages.title AS page_title', 'pages.slug AS page_slug')
            ->orderBy('c.tstamp', 'DESC')
            ->addOrderBy('c.uid', 'DESC')
            ->setMaxResults($limit);

        if ($languageId !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'c.sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * The page table keeps its real name in the join, because the page permission clause of TYPO3 refers to it
     * as "pages"
     *
     * @param list<int>|null $pageUids
     * @throws UserNotFoundException
     */
    private function getContentQueryBuilder(?array $pageUids): QueryBuilder
    {
        $queryBuilder = $this->getQueryBuilder(ContentRepository::TABLE_NAME);
        $queryBuilder
            ->from(ContentRepository::TABLE_NAME, 'c')
            ->join(
                'c',
                PageRepository::TABLE_NAME,
                'pages',
                $queryBuilder->expr()->eq('pages.uid', $queryBuilder->quoteIdentifier('c.pid'))
            )
            ->where($queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause()));

        if ($pageUids !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'c.pid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                )
            );
        }

        $allowedLanguageIds = $this->backendUserService->getAllowedLanguageIds();
        if ($allowedLanguageIds !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'c.sys_language_uid',
                    $queryBuilder->createNamedParameter(
                        [...$allowedLanguageIds, self::ALL_LANGUAGES],
                        Connection::PARAM_INT_ARRAY
                    )
                )
            );
        }

        return $queryBuilder;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function toTimestampMap(array $rows): array
    {
        $timestamps = [];
        foreach ($rows as $row) {
            $timestamps[(int)$row['page_uid']] = (int)$row['changed'];
        }
        return $timestamps;
    }

    private function getQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        return $queryBuilder;
    }
}
