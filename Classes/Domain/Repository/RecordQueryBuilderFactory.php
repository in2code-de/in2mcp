<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * Query builder for the records a backend user works on. Hidden records stay visible, because an editor sees them
 * in the backend as well. Deleted ones and the versions of other workspaces do not.
 */
readonly class RecordQueryBuilderFactory
{
    private const WORKSPACE_ASPECT = 'workspace';
    private const LIVE_WORKSPACE = 0;

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {
    }

    public function create(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->getWorkspaceId()));
        return $queryBuilder;
    }

    private function getWorkspaceId(): int
    {
        return (int)$this->context->getPropertyFromAspect(self::WORKSPACE_ASPECT, 'id', self::LIVE_WORKSPACE);
    }
}
