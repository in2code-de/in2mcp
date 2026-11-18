<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * The workspace a request works in, and the records as they look in it. In live, and without EXT:workspaces at
 * all, a record is returned as it is.
 */
readonly class WorkspaceService
{
    public const LIVE_WORKSPACE = 0;

    private const WORKSPACE_ASPECT = 'workspace';
    private const VERSION_STATE_FIELD = 't3ver_state';

    public function __construct(private Context $context)
    {
    }

    public function getWorkspaceId(): int
    {
        return (int)$this->context->getPropertyFromAspect(self::WORKSPACE_ASPECT, 'id', self::LIVE_WORKSPACE);
    }

    /**
     * The version of the current workspace under the uid of the live record, exactly as the backend shows it.
     * Null when the record is deleted in this workspace.
     *
     * @param array<string, mixed> $record Has to contain the uid
     * @return array<string, mixed>|null
     */
    public function overlayRecord(string $table, array $record): ?array
    {
        $workspaceId = $this->getWorkspaceId();
        if ($workspaceId === self::LIVE_WORKSPACE) {
            return $record;
        }

        // The core has no injectable service for the overlay of a backend record
        $overlay = $record;
        BackendUtility::workspaceOL($table, $overlay, $workspaceId);

        $isDeleted = is_array($overlay) === false
            || VersionState::tryFrom((int)($overlay[self::VERSION_STATE_FIELD] ?? 0))
                === VersionState::DELETE_PLACEHOLDER;

        return $isDeleted ? null : array_intersect_key($overlay, $record);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function overlayRecords(string $table, array $records): array
    {
        return array_values(array_filter(
            array_map(fn(array $record): ?array => $this->overlayRecord($table, $record), $records),
            static fn(?array $record): bool => $record !== null
        ));
    }
}
