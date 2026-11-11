<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Schema\SchemaCollection;
use TYPO3\CMS\Core\Schema\SchemaInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TableAccessServiceTest extends UnitTestCase
{
    private const CONFIGURED_TABLES = ['pages', 'tt_content', 'sys_file', 'be_users', 'fe_users', 'tx_news'];

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

    #[Test]
    #[DoesNotPerformAssertions]
    public function assertReadableAcceptsReadOnlyTable(): void
    {
        $this->createSubjectWithPermission(true)->assertReadable('sys_file');
    }

    #[Test]
    public function assertWritableRefusesReadOnlyTable(): void
    {
        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756802216);
        $this->createSubjectWithPermission(true)->assertWritable('sys_file');
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

    #[Test]
    public function getWritableTablesReturnsSortedAllowedTablesWithoutDeniedAndReadOnlyTables(): void
    {
        $backendUserService = $this->createStub(BackendUserService::class);
        $backendUserService->method('isTableModifyAllowed')->willReturnCallback(
            static fn(string $table): bool => $table !== 'tx_news'
        );

        self::assertSame(['pages', 'tt_content'], $this->createSubject($backendUserService)->getWritableTables());
    }

    private function createSubjectWithPermission(bool $isAllowed): TableAccessService
    {
        $backendUserService = $this->createStub(BackendUserService::class);
        $backendUserService->method('isTableSelectAllowed')->willReturn($isAllowed);
        $backendUserService->method('isTableModifyAllowed')->willReturn($isAllowed);

        return $this->createSubject($backendUserService);
    }

    private function createSubject(BackendUserService $backendUserService): TableAccessService
    {
        $tcaSchemaFactory = $this->createStub(TcaSchemaFactory::class);
        $tcaSchemaFactory->method('has')->willReturnCallback(
            static fn(string $name): bool => in_array(explode('.', $name)[0], self::CONFIGURED_TABLES, true)
        );
        $tcaSchemaFactory->method('all')->willReturn($this->createSchemaCollection(self::CONFIGURED_TABLES));

        return new TableAccessService($backendUserService, $tcaSchemaFactory);
    }

    /**
     * @param string[] $tables
     */
    private function createSchemaCollection(array $tables): SchemaCollection
    {
        $schemas = [];
        foreach ($tables as $table) {
            $schema = $this->createStub(SchemaInterface::class);
            $schema->method('getName')->willReturn($table);
            $schemas[$table] = $schema;
        }
        return new SchemaCollection(array_reverse($schemas, true));
    }
}
