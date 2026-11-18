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
        // A task "Execute console commands" runs any command of the installation with full rights
        'tx_scheduler_task',
        'tx_scheduler_task_group',
    ];

    /**
     * Readable for a client that wants to understand the installation, but never written through MCP, for
     * administrators neither: a leaked api key must not be able to widen what a user may do or where files go.
     */
    private const READ_ONLY_TABLES = [
        'sys_file' => 'the file in the storage would stay behind. Use "delete_file" to delete a file.',
        'sys_file_storage' => 'a storage decides where files are written on the server.',
        'sys_filemounts' => 'a file mount widens the files of every user who has it.',
        'sys_workspace' => 'a workspace decides who may edit and publish its changes.',
        'sys_workspace_stage' => 'a stage decides who may move changes on to publication.',
        'sys_template' => 'TypoScript changes the output of the whole site. Change it in the backend.',
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
        $this->assertNotReadOnly($table);
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
            if (in_array($table, [...self::DENIED_TABLES, ...array_keys(self::READ_ONLY_TABLES)], true)) {
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
                'The table "' . $table . '" holds users, sessions, internal bookkeeping or scheduled commands and'
                . ' is never accessible through MCP, independent of the permissions of the backend user',
                1756801012
            );
        }
    }

    /**
     * @throws TableNotAccessibleException
     */
    private function assertNotReadOnly(string $table): void
    {
        if (array_key_exists($table, self::READ_ONLY_TABLES)) {
            throw new TableNotAccessibleException(
                'Records of the table "' . $table . '" are only read through MCP, not written: '
                . self::READ_ONLY_TABLES[$table],
                1756802216
            );
        }
    }
}
