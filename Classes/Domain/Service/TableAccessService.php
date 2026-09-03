<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Decides which tables a MCP client may read and write.
 *
 * The permissions of the backend user stay the authority. This service only adds a hard denial on top of them
 * for tables that hold authentication data or internal bookkeeping: an administrator may edit backend users in
 * the backend, but an api key that leaked must never be able to create one, and a client has no reason to write
 * the log or the reference index.
 */
class TableAccessService
{
    /**
     * Refused for every user, including administrators
     */
    private const DENIED_TABLES = [
        'be_users',
        'be_groups',
        'be_sessions',
        'fe_users',
        'fe_groups',
        'fe_sessions',
        'sys_history',
        'sys_log',
        'sys_refindex',
        'sys_registry',
        'sys_file_processedfile',
        'sys_lockedrecords',
    ];

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly TcaSchemaFactory $tcaSchemaFactory
    ) {
    }

    /**
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     */
    public function assertReadable(string $table): void
    {
        $this->assertNotDenied($table);
        $this->assertConfigured($table);

        if ($this->backendUserService->isTableSelectAllowed($table) === false) {
            throw new TableNotAccessibleException(
                'The backend user is not allowed to read records of type "' . $table . '"',
                1756801000
            );
        }
    }

    /**
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     */
    public function assertWritable(string $table): void
    {
        $this->assertNotDenied($table);
        $this->assertConfigured($table);

        if ($this->backendUserService->isTableModifyAllowed($table) === false) {
            throw new TableNotAccessibleException(
                'The backend user is not allowed to write records of type "' . $table . '"',
                1756801004
            );
        }
    }

    /**
     * Every table this client may write, so it can find out what is possible instead of trying table names
     *
     * @return string[]
     * @throws UserNotFoundException
     */
    public function getWritableTables(): array
    {
        $tables = [];
        foreach ($this->tcaSchemaFactory->all()->getNames() as $table) {
            if (in_array($table, self::DENIED_TABLES, true)) {
                continue;
            }
            if ($this->backendUserService->isTableModifyAllowed($table)) {
                $tables[] = $table;
            }
        }
        sort($tables);
        return $tables;
    }

    /**
     * Checked after the denial, because a denied table does not necessarily have a schema - "sys_log"
     * has none - and "there is no such table" would be a misleading answer for a table that is refused on
     * purpose.
     *
     * @throws TableNotAccessibleException
     */
    private function assertConfigured(string $table): void
    {
        // A name with a dot addresses a sub schema like "tt_content.textmedia" in the Schema API, which is a
        // record type and no table - only a main schema is an answer to "is there such a table here"
        if (str_contains($table, '.') || $this->tcaSchemaFactory->has($table) === false) {
            throw new TableNotAccessibleException(
                'There is no table "' . $table . '" in this installation.'
                . ' Call "get_schema" to see the tables that exist here.',
                1756801008
            );
        }
    }

    /**
     * @throws TableNotAccessibleException
     */
    private function assertNotDenied(string $table): void
    {
        if (in_array($table, self::DENIED_TABLES, true)) {
            throw new TableNotAccessibleException(
                'The table "' . $table . '" holds authentication or bookkeeping data and is never accessible'
                . ' through MCP, independent of the permissions of the backend user',
                1756801012
            );
        }
    }
}
