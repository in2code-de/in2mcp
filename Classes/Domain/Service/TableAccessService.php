<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Event\TableAccess\ModifyTableAccessEvent;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Decides which tables a MCP client may read and write.
 *
 * The permissions of the backend user stay the authority. This service only adds a hard denial on top of them
 * for tables that hold authentication data or internal bookkeeping: an administrator may edit backend users in
 * the backend, but an api key that leaked must never be able to create one, and a client has no reason to write
 * the log or the reference index. An installation adds its own tables to that denial with the setting
 * "deniedTables", for example tables with personal data. Both built-in lists can be changed in code with the
 * event ModifyTableAccessEvent.
 */
class TableAccessService
{
    /**
     * Refused for every user, including administrators, unless a listener of ModifyTableAccessEvent says otherwise
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
        'tx_in2mcp_oauth_client',
        'tx_in2mcp_oauth_grant',
        // A task "Execute console commands" runs any command of the installation with full rights
        'tx_scheduler_task',
        'tx_scheduler_task_group',
    ];

    // Ends an entry of "deniedTables" that stands for every table starting with it
    public const WILDCARD = '*';

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

    private ?ModifyTableAccessEvent $tableAccess = null;

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly ConfigurationService $configurationService,
        private readonly EventDispatcherInterface $eventDispatcher
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
     * @throws UserNotFoundException
     */
    public function isReadable(string $table): bool
    {
        try {
            $this->assertReadable($table);
            $isReadable = true;
        } catch (TableNotAccessibleException) {
            $isReadable = false;
        }
        return $isReadable;
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
            if ($this->isDenied($table) || array_key_exists($table, $this->getReadOnlyTables())) {
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
     * Every denied table, for a query that has to leave them out - an entry ending with WILDCARD stands for every
     * table starting with it
     *
     * @return list<string>
     */
    public function getDeniedTables(): array
    {
        return [...$this->getBuiltInDeniedTables(), ...$this->configurationService->getDeniedTables()];
    }

    public function isDenied(string $table): bool
    {
        return in_array($table, $this->getBuiltInDeniedTables(), true) || $this->isDeniedByConfiguration($table);
    }

    /**
     * @throws TableNotAccessibleException
     */
    public function assertNotDenied(string $table): void
    {
        if (in_array($table, $this->getBuiltInDeniedTables(), true)) {
            throw new TableNotAccessibleException(
                'The table "' . $table . '" holds users, sessions, internal bookkeeping or scheduled commands and'
                . ' is never accessible through MCP, independent of the permissions of the backend user',
                1756801012
            );
        }
        if ($this->isDeniedByConfiguration($table)) {
            throw new TableNotAccessibleException(
                'The table "' . $table . '" is denied for MCP by this installation, for example because it holds'
                . ' personal data. It is not accessible through MCP for any user - do not try to reach its records'
                . ' in another way.',
                1795509483
            );
        }
    }

    /**
     * @return list<string>
     */
    private function getBuiltInDeniedTables(): array
    {
        return $this->getTableAccess()->getDeniedTables();
    }

    /**
     * @return array<string, string>
     */
    private function getReadOnlyTables(): array
    {
        return $this->getTableAccess()->getReadOnlyTables();
    }

    // Dispatched once - the lists are code, they do not change during a request
    private function getTableAccess(): ModifyTableAccessEvent
    {
        $this->tableAccess ??= $this->eventDispatcher->dispatch(
            new ModifyTableAccessEvent(self::DENIED_TABLES, self::READ_ONLY_TABLES)
        );
        return $this->tableAccess;
    }

    private function isDeniedByConfiguration(string $table): bool
    {
        $matchingEntries = array_filter(
            $this->configurationService->getDeniedTables(),
            static fn (string $entry): bool => str_ends_with($entry, self::WILDCARD)
                ? str_starts_with($table, substr($entry, 0, -strlen(self::WILDCARD)))
                : $table === $entry
        );
        return $matchingEntries !== [];
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
    private function assertNotReadOnly(string $table): void
    {
        if (array_key_exists($table, $this->getReadOnlyTables())) {
            throw new TableNotAccessibleException(
                'Records of the table "' . $table . '" are only read through MCP, not written: '
                . $this->getReadOnlyTables()[$table],
                1756802216
            );
        }
    }
}
