<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Service\TcaService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reads content elements of a page
 */
readonly class ContentRepository
{
    public const TABLE_NAME = 'tt_content';

    /**
     * Field of b13/container that connects a content element to the container it lives in. Container columns
     * are shared by every container of a page, so the column alone does not identify a position.
     */
    public const CONTAINER_PARENT_FIELD = 'tx_container_parent';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaService $tcaService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function findByPage(int $pageUid, int $languageId = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $contentElements = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            fn(array $contentElement): array => $this->tcaService->cleanUpRecord(self::TABLE_NAME, $contentElement),
            $contentElements
        );
    }

    /**
     * Uid of the last content element of a column in sorting order, 0 if the column is empty.
     *
     * A container column has to be restricted to its container as well: every container of a page uses the same
     * column numbers, so without that restriction the last element of a foreign container would be returned and
     * the new element would end up in the wrong place.
     *
     * @throws Exception
     */
    public function findLastUidInColumn(
        int $pageUid,
        int $colPos,
        int $containerParentUid = 0,
        int $languageId = 0
    ): int {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $queryBuilder
            ->select('uid')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->orderBy('sorting', 'DESC')
            ->setMaxResults(1);

        if ($this->isContainerInstalled()) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    self::CONTAINER_PARENT_FIELD,
                    $queryBuilder->createNamedParameter($containerParentUid, Connection::PARAM_INT)
                )
            );
        }

        $uid = $queryBuilder->executeQuery()->fetchOne();

        return $uid === false ? 0 : (int)$uid;
    }

    /**
     * Whether b13/container is installed, which is the only reason the container field exists
     */
    public function isContainerInstalled(): bool
    {
        return $this->tcaService->isFieldOfTable(self::TABLE_NAME, self::CONTAINER_PARENT_FIELD);
    }

    /**
     * @throws Exception
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $contentElement = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $contentElement === false
            ? null
            : $this->tcaService->cleanUpRecord(self::TABLE_NAME, $contentElement);
    }
}
