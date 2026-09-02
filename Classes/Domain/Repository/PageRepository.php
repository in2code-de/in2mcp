<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reads pages restricted to what the authenticated backend user is allowed to see
 */
readonly class PageRepository
{
    public const TABLE_NAME = 'pages';

    private const TREE_FIELDS = ['uid', 'pid', 'title', 'nav_title', 'slug', 'doktype', 'hidden', 'sorting'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private BackendUserService $backendUserService,
        private TcaService $tcaService,
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

        if ($page === false || $this->backendUserService->isInWebMount($uid) === false) {
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
    public function findTree(int $parentUid, int $depth, int $languageId = 0): array
    {
        if ($depth < 1) {
            return [];
        }

        // An editor sees the tree of its page mounts, not the tree of the installation. Pages above a mount can
        // be readable by permission and are still outside the reach of that user.
        if ($parentUid === 0 && $this->backendUserService->hasFullTreeAccess() === false) {
            return $this->findMountedTrees($depth, $languageId);
        }

        if ($parentUid > 0 && $this->backendUserService->isInWebMount($parentUid) === false) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder();
        $pages = $queryBuilder
            ->select(...self::TREE_FIELDS)
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
            ->executeQuery()
            ->fetchAllAssociative();

        $tree = [];
        foreach ($pages as $page) {
            $page['doktypeName'] = $this->tcaService->getPageTypeName((int)$page['doktype']);
            $page['children'] = $this->findTree((int)$page['uid'], $depth - 1, $languageId);
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

        $pages = $queryBuilder
            ->select(...self::TREE_FIELDS)
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('title', $searchParameter),
                    $queryBuilder->expr()->like('nav_title', $searchParameter),
                    $queryBuilder->expr()->like('subtitle', $searchParameter),
                    $queryBuilder->expr()->like('slug', $searchParameter)
                ),
                $queryBuilder->expr()->and($this->backendUserService->getPagePermissionClause())
            )
            ->orderBy('title')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter(
            $pages,
            fn(array $page): bool => $this->backendUserService->isInWebMount((int)$page['uid'])
        ));
    }

    /**
     * The page mounts of the backend user as roots of the tree
     *
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function findMountedTrees(int $depth, int $languageId): array
    {
        $tree = [];
        foreach ($this->backendUserService->getWebMounts() as $mountUid) {
            $queryBuilder = $this->getQueryBuilder();
            $page = $queryBuilder
                ->select(...self::TREE_FIELDS)
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

            if ($page === false) {
                continue;
            }

            $page['doktypeName'] = $this->tcaService->getPageTypeName((int)$page['doktype']);
            $page['children'] = $this->findTree($mountUid, $depth - 1, $languageId);
            $tree[] = $page;
        }

        return $tree;
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

    private function getQueryBuilder()
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        // Hidden pages stay visible, because an editor sees them in the backend as well. Deleted ones do not.
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        return $queryBuilder;
    }
}
