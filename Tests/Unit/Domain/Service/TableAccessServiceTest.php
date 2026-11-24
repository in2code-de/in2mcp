<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use Closure;
use In2code\In2mcp\Domain\Event\TableAccess\ModifyTableAccessEvent;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Schema\SchemaCollection;
use TYPO3\CMS\Core\Schema\SchemaInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TableAccessServiceTest extends UnitTestCase
{
    private const CONFIGURED_TABLES = [
        'pages',
        'tt_content',
        'sys_file',
        'sys_file_storage',
        'sys_filemounts',
        'sys_workspace',
        'sys_workspace_stage',
        'sys_template',
        'be_users',
        'fe_users',
        'tx_news',
        'tx_powermail_domain_model_mail',
        'tx_powermail_domain_model_answer',
    ];

    private const CONFIGURED_DENIED_TABLES = ['tx_news', 'tx_powermail_domain_model_*'];

    public static function deniedTableDataProvider(): array
    {
        return [
            'backend users' => ['be_users'],
            'backend groups' => ['be_groups'],
            'backend sessions' => ['be_sessions'],
            'frontend users' => ['fe_users'],
            'log without schema' => ['sys_log'],
            'reference index' => ['sys_refindex'],
            'registry' => ['sys_registry'],
            'scheduler tasks' => ['tx_scheduler_task'],
            'scheduler task groups' => ['tx_scheduler_task_group'],
        ];
    }

    #[Test]
    #[DataProvider('deniedTableDataProvider')]
    public function assertReadableRefusesDeniedTableEvenIfUserMayReadIt(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801012);
        $this->createSubjectWithPermission(true)->assertReadable($table);
    }

    #[Test]
    #[DataProvider('deniedTableDataProvider')]
    public function assertWritableRefusesDeniedTableEvenIfUserMayWriteIt(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801012);
        $this->createSubjectWithPermission(true)->assertWritable($table);
    }

    #[Test]
    public function assertReadableAcceptsAllowedTable(): void
    {
        $backendUserService = $this->createMock(BackendUserService::class);
        $backendUserService->expects(self::once())
            ->method('isTableSelectAllowed')
            ->with('tt_content')
            ->willReturn(true);

        $this->createSubject($backendUserService)->assertReadable('tt_content');
    }

    #[Test]
    public function assertReadableRefusesTableTheUserMayNotRead(): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801000);
        $this->createSubjectWithPermission(false)->assertReadable('tx_news');
    }

    #[Test]
    public function assertWritableAcceptsAllowedTable(): void
    {
        $backendUserService = $this->createMock(BackendUserService::class);
        $backendUserService->expects(self::once())
            ->method('isTableModifyAllowed')
            ->with('pages')
            ->willReturn(true);

        $this->createSubject($backendUserService)->assertWritable('pages');
    }

    #[Test]
    public function assertWritableRefusesTableTheUserMayNotWrite(): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801004);
        $this->createSubjectWithPermission(false)->assertWritable('tx_news');
    }

    public static function readOnlyTableDataProvider(): array
    {
        return [
            'files' => ['sys_file'],
            'storages' => ['sys_file_storage'],
            'file mounts' => ['sys_filemounts'],
            'workspaces' => ['sys_workspace'],
            'workspace stages' => ['sys_workspace_stage'],
            'typoscript' => ['sys_template'],
        ];
    }

    #[Test]
    #[DataProvider('readOnlyTableDataProvider')]
    #[DoesNotPerformAssertions]
    public function assertReadableAcceptsReadOnlyTable(string $table): void
    {
        $this->createSubjectWithPermission(true)->assertReadable($table);
    }

    #[Test]
    #[DataProvider('readOnlyTableDataProvider')]
    public function assertWritableRefusesReadOnlyTable(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756802216);
        $this->createSubjectWithPermission(true)->assertWritable($table);
    }

    public static function unknownTableDataProvider(): array
    {
        return [
            'not configured' => ['tx_unknown'],
            'record type of a table' => ['tt_content.textmedia'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('unknownTableDataProvider')]
    public function assertReadableRefusesUnknownTable(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801008);
        $this->createSubjectWithPermission(true)->assertReadable($table);
    }

    #[Test]
    #[DataProvider('unknownTableDataProvider')]
    public function assertWritableRefusesUnknownTable(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801008);
        $this->createSubjectWithPermission(true)->assertWritable($table);
    }

    public static function configuredDeniedTableDataProvider(): array
    {
        return [
            'table name' => ['tx_news'],
            'wildcard' => ['tx_powermail_domain_model_answer'],
            'wildcard matching the prefix of another table' => ['tx_powermail_domain_model_mail'],
        ];
    }

    #[Test]
    #[DataProvider('configuredDeniedTableDataProvider')]
    public function assertReadableRefusesConfiguredDeniedTable(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1795509483);
        $this->createSubjectWithPermission(true, self::CONFIGURED_DENIED_TABLES)->assertReadable($table);
    }

    #[Test]
    #[DataProvider('configuredDeniedTableDataProvider')]
    public function assertWritableRefusesConfiguredDeniedTable(string $table): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1795509483);
        $this->createSubjectWithPermission(true, self::CONFIGURED_DENIED_TABLES)->assertWritable($table);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function assertReadableAcceptsTableThatOnlyStartsLikeAConfiguredTableWithoutWildcard(): void
    {
        $this->createSubjectWithPermission(true, ['tx_new'])->assertReadable('tx_news');
    }

    #[Test]
    public function getDeniedTablesAddsConfiguredTablesToBuiltInTables(): void
    {
        $deniedTables = $this->createSubjectWithPermission(true, self::CONFIGURED_DENIED_TABLES)->getDeniedTables();

        self::assertContains('be_users', $deniedTables);
        self::assertContains('tx_powermail_domain_model_*', $deniedTables);
    }

    #[Test]
    public function getWritableTablesLeavesOutConfiguredDeniedTables(): void
    {
        $writableTables = $this->createSubjectWithPermission(true, self::CONFIGURED_DENIED_TABLES)
            ->getWritableTables();

        self::assertSame(['pages', 'tt_content'], $writableTables);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function listenerReleasesABuiltInDeniedTable(): void
    {
        $listener = static fn (ModifyTableAccessEvent $event) => $event->setDeniedTables(
            array_diff($event->getDeniedTables(), ['fe_users'])
        );

        $this->createSubjectWithListener($listener)->assertWritable('fe_users');
    }

    #[Test]
    public function listenerDeniesAFurtherTable(): void
    {
        $listener = static fn (ModifyTableAccessEvent $event) => $event->setDeniedTables(
            [...$event->getDeniedTables(), 'tx_news']
        );

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801012);
        $this->createSubjectWithListener($listener)->assertReadable('tx_news');
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function listenerReleasesAReadOnlyTableForWriting(): void
    {
        $listener = static function (ModifyTableAccessEvent $event): void {
            $readOnlyTables = $event->getReadOnlyTables();
            unset($readOnlyTables['sys_template']);
            $event->setReadOnlyTables($readOnlyTables);
        };

        $this->createSubjectWithListener($listener)->assertWritable('sys_template');
    }

    #[Test]
    public function listenerMakesAFurtherTableReadOnly(): void
    {
        $listener = static fn (ModifyTableAccessEvent $event) => $event->setReadOnlyTables(
            [...$event->getReadOnlyTables(), 'tx_news' => 'news are imported.']
        );
        $subject = $this->createSubjectWithListener($listener);

        $subject->assertReadable('tx_news');
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionMessage('news are imported.');
        $subject->assertWritable('tx_news');
    }

    #[Test]
    public function listenerCanNotReleaseATableOfTheSetting(): void
    {
        $listener = static fn (ModifyTableAccessEvent $event) => $event->setDeniedTables([]);
        $backendUserService = self::createStub(BackendUserService::class);
        $backendUserService->method('isTableSelectAllowed')->willReturn(true);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1795509483);
        $this->createSubject($backendUserService, ['tx_news'], $listener)->assertReadable('tx_news');
    }

    #[Test]
    public function getWritableTablesReturnsSortedAllowedTablesWithoutDeniedAndReadOnlyTables(): void
    {
        $backendUserService = self::createStub(BackendUserService::class);
        $backendUserService->method('isTableModifyAllowed')->willReturnCallback(
            static fn (string $table): bool => str_starts_with($table, 'tx_') === false
        );

        self::assertSame(['pages', 'tt_content'], $this->createSubject($backendUserService)->getWritableTables());
    }

    private function createSubjectWithListener(Closure $listener): TableAccessService
    {
        $backendUserService = self::createStub(BackendUserService::class);
        $backendUserService->method('isTableSelectAllowed')->willReturn(true);
        $backendUserService->method('isTableModifyAllowed')->willReturn(true);

        return $this->createSubject($backendUserService, [], $listener);
    }

    /**
     * @param list<string> $deniedTables
     */
    private function createSubjectWithPermission(bool $isAllowed, array $deniedTables = []): TableAccessService
    {
        $backendUserService = self::createStub(BackendUserService::class);
        $backendUserService->method('isTableSelectAllowed')->willReturn($isAllowed);
        $backendUserService->method('isTableModifyAllowed')->willReturn($isAllowed);

        return $this->createSubject($backendUserService, $deniedTables);
    }

    /**
     * @param list<string> $deniedTables
     */
    private function createSubject(
        BackendUserService $backendUserService,
        array $deniedTables = [],
        ?Closure $listener = null
    ): TableAccessService {
        $eventDispatcher = self::createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnCallback(
            static function (object $event) use ($listener): object {
                $listener?->__invoke($event);
                return $event;
            }
        );
        $configurationService = self::createStub(ConfigurationService::class);
        $configurationService->method('getDeniedTables')->willReturn($deniedTables);

        $tcaSchemaFactory = self::createStub(TcaSchemaFactory::class);
        $tcaSchemaFactory->method('has')->willReturnCallback(
            static fn (string $name): bool => in_array(explode('.', $name)[0], self::CONFIGURED_TABLES, true)
        );
        $tcaSchemaFactory->method('all')->willReturn($this->createSchemaCollection(self::CONFIGURED_TABLES));

        return new TableAccessService($backendUserService, $tcaSchemaFactory, $configurationService, $eventDispatcher);
    }

    /**
     * @param string[] $tables
     */
    private function createSchemaCollection(array $tables): SchemaCollection
    {
        $schemas = [];
        foreach ($tables as $table) {
            $schema = self::createStub(SchemaInterface::class);
            $schema->method('getName')->willReturn($table);
            $schemas[$table] = $schema;
        }
        return new SchemaCollection(array_reverse($schemas, true));
    }
}
