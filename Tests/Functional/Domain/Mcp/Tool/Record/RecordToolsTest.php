<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RecordToolsTest extends AbstractFunctionalTestCase
{
    private const PASSWORD_FIELD = 'tx_in2mcpfixture_secret';

    protected array $testExtensionsToLoad = [
        'in2code/in2mcp',
        __DIR__ . '/../../../../Fixtures/Extensions/in2mcp_fixture',
    ];

    #[Test]
    public function getRecordReturnsARecordOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1]);

        self::assertSame('First element', $result['record']['header']);
    }

    #[Test]
    public function getRecordRefusesARecordOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 3]);
    }

    #[Test]
    public function getRecordRefusesATableTheEditorMayNotRead(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_record', ['table' => 'sys_category', 'uid' => 1]);
    }

    #[Test]
    public function getRecordRefusesADeniedTableEvenForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_record', ['table' => 'be_users', 'uid' => 1]);
    }

    #[Test]
    public function getRecordRefusesAConfiguredDeniedTableEvenForAnAdministrator(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['deniedTables'] = 'tt_cont*';
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->expectExceptionCode(1795509483);
        $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1]);
    }

    #[Test]
    public function findRecordsRefusesAConfiguredDeniedTable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['deniedTables'] = 'tt_content';
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->expectExceptionCode(1795509483);
        $this->callTool('find_records', ['table' => 'tt_content', 'pid' => 2]);
    }

    #[Test]
    public function createRecordRefusesAConfiguredDeniedTable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['deniedTables'] = 'tt_content';
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->expectExceptionCode(1795509483);
        $this->callTool('create_record', ['table' => 'tt_content', 'pid' => 2, 'fields' => ['header' => 'x']]);
    }

    #[Test]
    public function getRecordNeverReturnsAPasswordField(): void
    {
        $this->loginAs(self::ADMIN);
        $this->writeSecret(1, 'very secret');

        $result = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1]);

        self::assertSame('First element', $result['record']['header']);
        self::assertArrayNotHasKey(self::PASSWORD_FIELD, $result['record']);
    }

    #[Test]
    public function findRecordsNeverReturnsAPasswordField(): void
    {
        $this->loginAs(self::ADMIN);
        $this->writeSecret(1, 'very secret');

        $records = $this->callTool('find_records', ['table' => 'tt_content'])['records'];

        self::assertNotSame([], $records);
        foreach ($records as $record) {
            self::assertArrayNotHasKey(self::PASSWORD_FIELD, $record);
        }
    }

    #[Test]
    public function findRecordsReturnsOnlyRecordsOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('find_records', ['table' => 'tt_content']);

        self::assertSame([1, 2], array_map('intval', array_column($result['records'], 'uid')));
    }

    #[Test]
    public function findRecordsPagesThroughEveryRecordWithNextOffset(): void
    {
        $this->loginAs(self::ADMIN);
        self::assertSame([1, 2, 3], $this->findAllUidsPageByPage('tt_content'));

        $this->loginAs(self::EDITOR);
        self::assertSame([1, 2], $this->findAllUidsPageByPage('tt_content'));
    }

    #[Test]
    public function findRecordsReportsTheLimitItApplies(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('find_records', ['table' => 'tt_content', 'limit' => 5000]);

        self::assertSame(500, $result['limit']);
        self::assertNull($result['nextOffset']);
    }

    #[Test]
    public function findRecordsFiltersByField(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool(
            'find_records',
            ['table' => 'tt_content', 'filters' => ['header' => 'Second element']]
        );

        self::assertSame([2], array_map('intval', array_column($result['records'], 'uid')));
    }

    #[Test]
    public function findRecordsWithAListMatchesAnyOfItsValues(): void
    {
        $this->loginAs(self::ADMIN);
        $numbers = $this->callTool('find_records', ['table' => 'tt_content', 'filters' => ['uid' => [1, 3]]]);
        $texts = $this->callTool(
            'find_records',
            ['table' => 'tt_content', 'filters' => ['header' => ['First element', 'Second element']]]
        );
        $this->loginAs(self::EDITOR);
        $mounted = $this->callTool('find_records', ['table' => 'tt_content', 'filters' => ['uid' => [1, 3]]]);

        self::assertSame([1, 3], $this->getSortedUids($numbers['records']));
        self::assertSame([1, 2], $this->getSortedUids($texts['records']));
        self::assertSame([1], $this->getSortedUids($mounted['records']));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidFilterListDataProvider(): array
    {
        return [
            'empty list' => [[]],
            'object' => [['uid' => 1]],
            'nested list' => [[[1, 2]]],
            'null in list' => [[1, null]],
            'too many values' => [range(1, 1001)],
        ];
    }

    #[Test]
    #[DataProvider('invalidFilterListDataProvider')]
    public function findRecordsRefusesAnInvalidList(mixed $value): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('find_records', ['table' => 'tt_content', 'filters' => ['uid' => $value]]);
    }

    #[Test]
    public function findRecordsWithNullFindsEmptyFields(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('find_records', ['table' => 'pages', 'filters' => ['nav_title' => null]]);

        self::assertSame([1, 2, 4, 5], $this->getSortedUids($result['records']));
    }

    #[Test]
    public function findRecordsWithNullFindsEmptyNumbers(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('find_records', ['table' => 'pages', 'filters' => ['media' => null]]);

        self::assertNotContains(2, $this->getSortedUids($result['records']));
        self::assertContains(3, $this->getSortedUids($result['records']));
    }

    #[Test]
    public function findRecordsWithNullRefusesAFieldWithoutAnEmptyValue(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('find_records', ['table' => 'tt_content', 'filters' => ['CType' => null]]);
    }

    #[Test]
    public function findRecordsRefusesAPageOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('find_records', ['table' => 'tt_content', 'pid' => 4]);
    }

    #[Test]
    public function createRecordAppendsAContentElement(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'create_record',
            ['table' => 'tt_content', 'pid' => 2, 'fields' => ['CType' => 'text', 'header' => 'Created']]
        );

        self::assertSame(2, (int)$result['record']['pid']);
        self::assertSame('Created', $result['record']['header']);
    }

    #[Test]
    public function createRecordRefusesATableTheEditorMayNotModify(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('create_record', ['table' => 'sys_category', 'pid' => 2, 'fields' => ['title' => 'x']]);
    }

    #[Test]
    public function createRecordRefusesABackendUserEvenForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'create_record',
            ['table' => 'be_users', 'pid' => 0, 'fields' => ['username' => 'intruder', 'admin' => 1]]
        );
    }

    #[Test]
    public function updateRecordWritesAField(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'update_record',
            ['table' => 'tt_content', 'uid' => 2, 'fields' => ['header' => 'Updated']]
        );

        self::assertSame('Updated', $result['record']['header']);
    }

    #[Test]
    public function updateRecordRefusesTheSortingField(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('update_record', ['table' => 'tt_content', 'uid' => 2, 'fields' => ['sorting' => 1]]);
    }

    #[Test]
    public function deleteRecordRemovesTheRecord(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('delete_record', ['table' => 'tt_content', 'uid' => 2]);

        self::assertTrue($result['deleted']);
        $this->expectException(ClientFacingException::class);
        $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 2]);
    }

    #[Test]
    public function deleteRecordRefusesARecordOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('delete_record', ['table' => 'tt_content', 'uid' => 3]);
    }

    #[Test]
    public function moveRecordPutsAnElementBehindAnother(): void
    {
        $this->loginAs(self::EDITOR);

        $this->callTool('move_record', ['table' => 'tt_content', 'uid' => 1, 'afterRecordUid' => 2]);

        $result = $this->callTool('get_page', ['pageUid' => 2]);
        self::assertSame([2, 1], array_map('intval', array_column($result['content']['colPos_0'], 'uid')));
    }

    #[Test]
    public function moveRecordIntoAnotherPageAndColumn(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'move_record',
            ['table' => 'tt_content', 'uid' => 1, 'intoPageUid' => 3, 'colPos' => 2]
        );

        self::assertSame(3, (int)$result['record']['pid']);
        self::assertSame(2, (int)$result['record']['colPos']);
    }

    #[Test]
    public function moveRecordAppendsAtTheEndOfTheTargetColumn(): void
    {
        $this->loginAs(self::EDITOR);
        $this->callTool('move_record', ['table' => 'tt_content', 'uid' => 1, 'intoPageUid' => 3]);

        $this->callTool('move_record', ['table' => 'tt_content', 'uid' => 2, 'intoPageUid' => 3]);

        $result = $this->callTool('get_page', ['pageUid' => 3]);
        self::assertSame([1, 2], array_map('intval', array_column($result['content']['colPos_0'], 'uid')));
    }

    #[Test]
    public function moveRecordRefusesATargetOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('move_record', ['table' => 'tt_content', 'uid' => 1, 'intoPageUid' => 4]);
    }

    #[Test]
    public function moveRecordRequiresExactlyOneTarget(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('move_record', ['table' => 'tt_content', 'uid' => 1]);
    }

    #[Test]
    public function localizeRecordCreatesATranslationForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);
        $this->callTool('localize_record', ['table' => 'pages', 'uid' => 2, 'languageId' => 1]);

        $result = $this->callTool('localize_record', ['table' => 'tt_content', 'uid' => 1, 'languageId' => 1]);

        self::assertSame(1, (int)$result['record']['sys_language_uid']);
        self::assertSame(1, (int)$result['record']['l18n_parent']);
    }

    #[Test]
    public function localizeRecordRefusesALanguageTheEditorMayNotAccess(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('localize_record', ['table' => 'tt_content', 'uid' => 1, 'languageId' => 1]);
    }

    #[Test]
    public function localizeRecordRefusesTheDefaultLanguage(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('localize_record', ['table' => 'tt_content', 'uid' => 1, 'languageId' => 0]);
    }

    private function writeSecret(int $contentUid, string $secret): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->update('tt_content', [self::PASSWORD_FIELD => $secret], ['uid' => $contentUid]);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<int>
     */
    /**
     * @return list<int>
     */
    private function findAllUidsPageByPage(string $table): array
    {
        $uids = [];
        $offset = 0;
        $calls = 0;
        do {
            $result = $this->callTool('find_records', ['table' => $table, 'limit' => 1, 'offset' => $offset]);
            $uids = [...$uids, ...array_map('intval', array_column($result['records'], 'uid'))];
            $offset = $result['nextOffset'];
            $calls++;
        } while ($offset !== null && $calls < 10);
        sort($uids);
        return $uids;
    }

    private function getSortedUids(array $records): array
    {
        $uids = array_map('intval', array_column($records, 'uid'));
        sort($uids);
        return $uids;
    }
}
