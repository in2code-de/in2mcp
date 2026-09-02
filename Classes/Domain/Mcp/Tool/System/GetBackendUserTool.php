<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\System;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Tells the client which backend user it acts as and what that user is allowed to do. A client should call this
 * first, because every other tool is restricted by exactly these permissions.
 */
class GetBackendUserTool extends AbstractTool
{
    private const RELEVANT_TABLES = ['pages', 'tt_content', 'sys_file_reference'];

    public function __construct(private readonly BackendUserService $backendUserService)
    {
    }

    public function getName(): string
    {
        return 'get_backend_user';
    }

    public function getDescription(): string
    {
        return 'Returns the TYPO3 backend user this connection acts as, including its permissions, its page'
            . ' mounts and the tables it may read or write. Every other tool is restricted to these permissions.';
    }

    /**
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $backendUser = $this->backendUserService->getBackendUser();

        return [
            'uid' => $this->backendUserService->getUserId(),
            'username' => (string)($backendUser->user['username'] ?? ''),
            'realName' => (string)($backendUser->user['realName'] ?? ''),
            'email' => (string)($backendUser->user['email'] ?? ''),
            'isAdmin' => $this->backendUserService->isAdmin(),
            'workspace' => (int)($backendUser->workspace ?? 0),
            'pageMounts' => $this->getPageMounts(),
            'tablePermissions' => $this->getTablePermissions(),
        ];
    }

    /**
     * @throws UserNotFoundException
     */
    private function getPageMounts(): array
    {
        // Only administrators see the whole tree. For everybody else an empty list of mounts is the opposite
        // of "no restriction": without a mount no page is reachable at all.
        if ($this->backendUserService->hasFullTreeAccess()) {
            return ['description' => 'Full page tree', 'pageUids' => []];
        }

        $webMounts = $this->backendUserService->getWebMounts();
        if ($webMounts === []) {
            return [
                'description' => 'No page mounts, so this user cannot read any page',
                'pageUids' => [],
            ];
        }

        return ['description' => 'Restricted to these page trees', 'pageUids' => $webMounts];
    }

    /**
     * @throws UserNotFoundException
     */
    private function getTablePermissions(): array
    {
        $permissions = [];
        foreach (self::RELEVANT_TABLES as $table) {
            $permissions[$table] = [
                'read' => $this->backendUserService->isTableSelectAllowed($table),
                'write' => $this->backendUserService->isTableModifyAllowed($table),
            ];
        }
        return $permissions;
    }
}
