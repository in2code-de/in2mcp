<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Schema\SchemaCollection;
use TYPO3\CMS\Core\Schema\SchemaInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TableAccessServiceTest extends UnitTestCase
{
    private const CONFIGURED_TABLES = ['pages', 'tt_content', 'sys_file', 'be_users', 'fe_users', 'tx_news'];

    private BackendUserService&MockObject $backendUserService;
    private TableAccessService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backendUserService = $this->createMock(BackendUserService::class);

        $tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);
        $tcaSchemaFactory->method('has')->willReturnCallback(
            static fn(string $name): bool => in_array(explode('.', $name)[0], self::CONFIGURED_TABLES, true)
        );
        $tcaSchemaFactory->method('all')->willReturn($this->createSchemaCollection(self::CONFIGURED_TABLES));

        $this->subject = new TableAccessService($this->backendUserService, $tcaSchemaFactory);
    }

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
        $this->backendUserService->method('isTableSelectAllowed')->willReturn(true);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801012);
        $this->subject->assertReadable($table);
    }

    #[Test]
    #[DataProvider('deniedTableDataProvider')]
    public function assertWritableRefusesDeniedTableEvenIfUserMayWriteIt(string $table): void
    {
        $this->backendUserService->method('isTableModifyAllowed')->willReturn(true);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801012);
        $this->subject->assertWritable($table);
    }

    #[Test]
    public function assertReadableAcceptsAllowedTable(): void
    {
        $this->backendUserService->expects(self::once())
            ->method('isTableSelectAllowed')
            ->with('tt_content')
            ->willReturn(true);

        $this->subject->assertReadable('tt_content');
    }

    #[Test]
    public function assertReadableRefusesTableTheUserMayNotRead(): void
    {
        $this->backendUserService->method('isTableSelectAllowed')->willReturn(false);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801000);
        $this->subject->assertReadable('tx_news');
    }

    #[Test]
    public function assertWritableAcceptsAllowedTable(): void
    {
        $this->backendUserService->expects(self::once())
            ->method('isTableModifyAllowed')
            ->with('pages')
            ->willReturn(true);

        $this->subject->assertWritable('pages');
    }

    #[Test]
    public function assertWritableRefusesTableTheUserMayNotWrite(): void
    {
        $this->backendUserService->method('isTableModifyAllowed')->willReturn(false);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801004);
        $this->subject->assertWritable('tx_news');
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function assertReadableAcceptsReadOnlyTable(): void
    {
        $this->backendUserService->method('isTableSelectAllowed')->willReturn(true);

        $this->subject->assertReadable('sys_file');
    }

    #[Test]
    public function assertWritableRefusesReadOnlyTable(): void
    {
        $this->backendUserService->method('isTableModifyAllowed')->willReturn(true);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756802216);
        $this->subject->assertWritable('sys_file');
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
        $this->backendUserService->method('isTableSelectAllowed')->willReturn(true);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801008);
        $this->subject->assertReadable($table);
    }

    #[Test]
    #[DataProvider('unknownTableDataProvider')]
    public function assertWritableRefusesUnknownTable(string $table): void
    {
        $this->backendUserService->method('isTableModifyAllowed')->willReturn(true);

        $this->expectException(TableNotAccessibleException::class);
        $this->expectExceptionCode(1756801008);
        $this->subject->assertWritable($table);
    }

    #[Test]
    public function getWritableTablesReturnsSortedAllowedTablesWithoutDeniedAndReadOnlyTables(): void
    {
        $this->backendUserService->method('isTableModifyAllowed')->willReturnCallback(
            static fn(string $table): bool => $table !== 'tx_news'
        );

        self::assertSame(['pages', 'tt_content'], $this->subject->getWritableTables());
    }

    /**
     * @param string[] $tables
     */
    private function createSchemaCollection(array $tables): SchemaCollection
    {
        $schemas = [];
        foreach ($tables as $table) {
            $schema = $this->createMock(SchemaInterface::class);
            $schema->method('getName')->willReturn($table);
            $schemas[$table] = $schema;
        }
        return new SchemaCollection(array_reverse($schemas, true));
    }
}
