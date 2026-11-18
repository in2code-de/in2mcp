<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\PageTreeBudget;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Domain\Service\WorkspaceService;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Reads pages restricted to what the authenticated backend user is allowed to see
 */
readonly class PageRepository
{
    public const TABLE_NAME = 'pages';

    private const DEFAULT_LANGUAGE = 0;
    /**
     * Shown by the page tree of the backend to everybody, even where doktype and hidden are exclude fields
     */
    private const TREE_FIELDS = ['uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'sorting'];

    /**
     * Exclude fields, returned and searched only for a user who may read them
     */
    private const OPTIONAL_TREE_FIELDS = ['nav_title'];
    private const OPTIONAL_SEARCH_FIELDS = ['nav_title', 'subtitle'];
    private const SEARCH_FIELDS = ['title', 'slug'];

    public function __construct(
        private RecordQueryBuilderFactory $recordQueryBuilderFactory,
        private BackendUserService $backendUserService,
        private TcaService $tcaService,
        private WorkspaceService $workspaceService,
    ) {
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->getQueryBuilder();
        $page = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause())
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        $page = $page === false ? null : $this->workspaceService->overlayRecord(self::TABLE_NAME, $page);
        if ($page === null || $this->backendUserService->isInWebMount($uid) === false) {
            return null;
        }

        return $this->tcaService->cleanUpRecord(self::TABLE_NAME, $page);
    }

    /**
     * Builds the page tree below a given page as a nested structure
     *
     * @throws Exception
     * @throws UserNotFoundException
     */
    /**
     * @return array{tree: list<array<string, mixed>>, truncated: bool}
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findTree(int $parentUid, int $depth, int $languageId, int $maximumPages): array
    {
        $budget = new PageTreeBudget($maximumPages);
        $tree = $this->buildTree($parentUid, $depth, $languageId, $budget);
        return ['tree' => $tree, 'truncated' => $budget->isTruncated()];
    }

    /**
     * @return list<array<string, mixed>>
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function buildTree(int $parentUid, int $depth, int $languageId, PageTreeBudget $budget): array
    {
        if ($depth < 1) {
            return [];
        }

        // An editor sees the tree of its page mounts, not the tree of the installation. Pages above a mount can
        // be readable by permission and are still outside the reach of that user.
        if ($parentUid === 0 && $this->backendUserService->hasFullTreeAccess() === false) {
            return $this->findMountedTrees($depth, $languageId, $budget);
        }

        if ($parentUid > 0 && $this->backendUserService->isInWebMount($parentUid) === false) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder();
        $pages = $queryBuilder
            ->select(...$this->getTreeFields())
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause())
            )
            ->orderBy('sorting')
            // One more than the budget allows, so a cut is noticed without loading the rest
            ->setMaxResults($budget->getRemainingPages() + 1)
            ->executeQuery()
            ->fetchAllAssociative();

        $tree = [];
        foreach ($this->workspaceService->overlayRecords(self::TABLE_NAME, $pages) as $page) {
            if ($budget->take() === false) {
                break;
            }
            $page['doktypeName'] = $this->tcaService->getPageTypeName((int)$page['doktype']);
            $page['children'] = $this->buildTree((int)$page['uid'], $depth - 1, $languageId, $budget);
            $tree[] = $page;
        }

        return $tree;
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function search(string $searchTerm, int $limit): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $searchParameter = $queryBuilder->createNamedParameter(
            '%' . $queryBuilder->escapeLikeWildcards($searchTerm) . '%'
        );

        $this->restrictToReadableLanguages($queryBuilder);
        $pages = $queryBuilder
            ->select(...$this->getTreeFields())
            ->from(self::TABLE_NAME)
            ->andWhere(
                $queryBuilder->expr()->or(...array_map(
                    static fn(string $fieldName): string => (string)$queryBuilder->expr()->like(
                        $fieldName,
                        $searchParameter
                    ),
                    [
                        ...self::SEARCH_FIELDS,
                        ...$this->tcaService->filterReadableFields(self::TABLE_NAME, self::OPTIONAL_SEARCH_FIELDS),
                    ]
                )),
                $queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause())
            )
            ->orderBy('title')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter(
            $this->workspaceService->overlayRecords(self::TABLE_NAME, $pages),
            fn(array $page): bool => $this->backendUserService->isInWebMount((int)$page['uid'])
        ));
    }

    /**
     * The page mounts of the backend user as roots of the tree
     *
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function findMountedTrees(int $depth, int $languageId, PageTreeBudget $budget): array
    {
        $tree = [];
        foreach ($this->backendUserService->getWebMounts() as $mountUid) {
            if ($budget->take() === false) {
                break;
            }
            $queryBuilder = $this->getQueryBuilder();
            $page = $queryBuilder
                ->select(...$this->getTreeFields())
                ->from(self::TABLE_NAME)
                ->where(
                    $queryBuilder->expr()->eq(
                        'uid',
                        $queryBuilder->createNamedParameter($mountUid, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause())
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            $page = $page === false ? null : $this->workspaceService->overlayRecord(self::TABLE_NAME, $page);
            if ($page === null) {
                continue;
            }

            $page['doktypeName'] = $this->tcaService->getPageTypeName((int)$page['doktype']);
            $page['children'] = $this->buildTree($mountUid, $depth - 1, $languageId, $budget);
            $tree[] = $page;
        }

        return $tree;
    }

    /**
     * @return list<int> the page itself and all pages below it in the default language, hidden ones included
     * @throws Exception
     */
    public function findSubtreeUids(int $rootUid): array
    {
        $subtreeUids = [$rootUid];
        $parentUids = [$rootUid];
        while ($parentUids !== []) {
            $queryBuilder = $this->getQueryBuilder();
            $childUids = $queryBuilder
                ->select('uid')
                ->from(self::TABLE_NAME)
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchFirstColumn();
            $parentUids = array_values(array_diff(array_map('intval', $childUids), $subtreeUids));
            $subtreeUids = array_merge($subtreeUids, $parentUids);
        }
        return $subtreeUids;
    }

    /**
     * Uid of the last child of a page in sorting order, 0 if the page has no children. Needed to append a new
     * page at the end, because DataHandler puts a record with a positive pid at the very beginning.
     *
     * @throws Exception
     */
    public function findLastChildUid(int $parentUid): int
    {
        $queryBuilder = $this->getQueryBuilder();
        $uid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false ? 0 : (int)$uid;
    }

    /**
     * Translations only in the languages the user may access - the default language stays, because the page tree
     * of the backend shows its pages to everybody
     *
     * @throws UserNotFoundException
     */
    private function restrictToReadableLanguages(QueryBuilder $queryBuilder): void
    {
        $allowedLanguageIds = $this->backendUserService->getAllowedLanguageIds();
        if ($allowedLanguageIds !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->in(
                'sys_language_uid',
                $queryBuilder->createNamedParameter(
                    array_values(array_unique([self::DEFAULT_LANGUAGE, ...$allowedLanguageIds])),
                    Connection::PARAM_INT_ARRAY
                )
            ));
        }
    }

    /**
     * @return list<string>
     * @throws UserNotFoundException
     */
    private function getTreeFields(): array
    {
        return [
            ...self::TREE_FIELDS,
            ...$this->tcaService->filterReadableFields(self::TABLE_NAME, self::OPTIONAL_TREE_FIELDS),
        ];
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->recordQueryBuilderFactory->create(self::TABLE_NAME);
    }
}
