<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class WorkspaceRepository
{
    public const TABLE_NAME = 'sys_workspace';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * Every workspace of the installation. Whether a user may enter one is decided by the core, which needs the
     * complete record for that, so all fields are selected.
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
