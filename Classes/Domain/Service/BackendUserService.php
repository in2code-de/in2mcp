<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Single point of access to the backend user that was authenticated for the current MCP request. All tools ask
 * this service instead of reading the global themselves, so the permission handling stays in one place.
 */
class BackendUserService
{
    /**
     * @throws UserNotFoundException
     */
    public function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (($backendUser instanceof BackendUserAuthentication) === false) {
            throw new UserNotFoundException('MCP: No authenticated backend user available', 1756800500);
        }

        return $backendUser;
    }

    /**
     * @throws UserNotFoundException
     */
    public function getUserId(): int
    {
        return (int)($this->getBackendUser()->user['uid'] ?? 0);
    }

    /**
     * @throws UserNotFoundException
     */
    public function isAdmin(): bool
    {
        return $this->getBackendUser()->isAdmin();
    }

    /**
     * SQL fragment that restricts a page query to the pages the backend user is allowed to see
     *
     * @throws UserNotFoundException
     */
    public function getPagePermissionClause(int $permission = Permission::PAGE_SHOW): string
    {
        return $this->getBackendUser()->getPagePermsClause($permission);
    }

    /**
     * @return int[] Page uids of the web mounts, an empty list means the whole tree (admin)
     * @throws UserNotFoundException
     */
    public function getWebMounts(): array
    {
        return array_map('intval', $this->getBackendUser()->getWebmounts());
    }

    /**
     * Whether a page lies inside one of the page mounts of the backend user. This is a restriction on top of the
     * page permissions: a page may be readable for everybody and still be outside the tree of this user.
     * Returns true for administrators.
     *
     * @throws UserNotFoundException
     */
    public function isInWebMount(int $pageUid): bool
    {
        return $this->getBackendUser()->isInWebMount($pageUid) !== null;
    }

    /**
     * Whether the backend user sees the whole page tree instead of dedicated mounts, which is the case for
     * administrators only.
     *
     * An empty list of page mounts must not be read as "no restriction". For an administrator it means the
     * whole tree, because administrators carry no mounts; for everybody else it means the opposite - no page at
     * all, which is exactly what isInWebMount() answers for such a user. TYPO3 has no setting that lifts this
     * any more, "lockBeUserToDBmounts" was removed.
     *
     * @throws UserNotFoundException
     */
    public function hasFullTreeAccess(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Writes an entry into the file section of the TYPO3 log, the same way the backend does for a file
     * operation. Record writes land there through the DataHandler already; a file that is brought into the
     * storage has to be traceable for an administrator in exactly the same way.
     *
     * @param array<string, mixed> $context
     * @throws UserNotFoundException
     */
    public function logFileAction(int $action, int $severity, string $message, array $context = []): void
    {
        $this->getBackendUser()->writelog(SystemLogType::FILE, $action, $severity, null, $message, $context);
    }

    /**
     * @throws UserNotFoundException
     */
    public function isTableModifyAllowed(string $table): bool
    {
        return $this->getBackendUser()->check('tables_modify', $table);
    }

    /**
     * @throws UserNotFoundException
     */
    public function isTableSelectAllowed(string $table): bool
    {
        return $this->getBackendUser()->check('tables_select', $table)
            || $this->getBackendUser()->check('tables_modify', $table);
    }
}
