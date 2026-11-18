<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RecordToolsTest extends AbstractFunctionalTestCase
{
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
    public function getRecordNeverReturnsAPasswordField(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('get_record', ['table' => 'pages', 'uid' => 2]);

        self::assertArrayNotHasKey('perms_userid', array_filter($result['record'], 'is_null'));
        self::assertSame('About', $result['record']['title']);
    }

    #[Test]
    public function findRecordsReturnsOnlyRecordsOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('find_records', ['table' => 'tt_content']);

        self::assertSame([1, 2], array_map('intval', array_column($result['records'], 'uid')));
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
}
