<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\WorkspaceRepository;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * The workspace a request works in, the records as they look in it, and the change to another workspace. In live,
 * and without EXT:workspaces at all, a record is returned as it is.
 */
readonly class WorkspaceService
{
    public const LIVE_WORKSPACE = 0;

    private const WORKSPACE_ASPECT = 'workspace';
    private const VERSION_STATE_FIELD = 't3ver_state';
    private const WORKSPACES_PACKAGE = 'workspaces';
    private const LIVE_WORKSPACE_TITLE = 'Live';

    public function __construct(
        private Context $context,
        private PackageManager $packageManager,
        private WorkspaceRepository $workspaceRepository,
        private BackendUserService $backendUserService,
    ) {
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
            array_map(fn (array $record): ?array => $this->overlayRecord($table, $record), $records),
            static fn (?array $record): bool => $record !== null
        ));
    }

    public function isWorkspacesInstalled(): bool
    {
        return $this->packageManager->isPackageActive(self::WORKSPACES_PACKAGE);
    }

    /**
     * Live and every workspace the backend user may enter, decided by the core exactly as for the workspace
     * selector of the backend
     *
     * @return list<array{uid: int, title: string, description: string, current: bool}>
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function findAccessibleWorkspaces(): array
    {
        $this->assertWorkspacesInstalled();
        $backendUser = $this->backendUserService->getBackendUser();
        $candidates = [
            ['uid' => self::LIVE_WORKSPACE, 'title' => self::LIVE_WORKSPACE_TITLE],
            ...$this->workspaceRepository->findAll(),
        ];

        $workspaces = [];
        foreach ($candidates as $candidate) {
            // checkWorkspace() is internal, but it is the only place where the core decides about the access
            if ($backendUser->checkWorkspace($candidate) !== false) {
                $workspaces[] = [
                    'uid' => (int)$candidate['uid'],
                    'title' => (string)$candidate['title'],
                    'description' => (string)($candidate['description'] ?? ''),
                    'current' => (int)$candidate['uid'] === $this->getWorkspaceId(),
                ];
            }
        }
        return $workspaces;
    }

    /**
     * Changes the workspace the same way the workspace selector of the backend does: the choice is stored at the
     * backend user, so the following requests work in it, and the backend shows it after its next reload.
     *
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function switchWorkspace(int $workspaceId): void
    {
        $this->assertWorkspacesInstalled();
        $backendUser = $this->backendUserService->getBackendUser();

        if ($backendUser->checkWorkspace($workspaceId) === false) {
            throw new ToolExecutionException(
                'The backend user may not work in the workspace ' . $workspaceId . ', or it does not exist. Call'
                . ' "get_workspaces" to see the workspaces this user may enter. Nothing was changed.',
                1756802400
            );
        }

        // setWorkspace() is internal, but it is exactly what the workspace selector of the backend calls
        $backendUser->setWorkspace($workspaceId);
        $this->context->setAspect(self::WORKSPACE_ASPECT, new WorkspaceAspect($backendUser->workspace));
    }

    /**
     * @throws ToolExecutionException
     */
    private function assertWorkspacesInstalled(): void
    {
        if ($this->isWorkspacesInstalled() === false) {
            throw new ToolExecutionException(
                'EXT:workspaces is not installed in this TYPO3, so there are no workspaces and every change is'
                . ' made in live.',
                1756802404
            );
        }
    }
}
