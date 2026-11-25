<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class GetPageToolTest extends AbstractFunctionalTestCase
{
    private const EDITOR_GROUP = 1;
    private const DELETED_PAGE = 1764079500;
    private const MISSING_PAGE = 1756800700;

    #[Test]
    public function returnsPageWithContentGroupedByColumn(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_page', ['pageUid' => 2]);

        self::assertSame('About', $result['page']['title']);
        self::assertSame([1, 2], array_column($result['content']['colPos_0'], 'uid'));
    }

    #[Test]
    public function refusesPageOutsideOfTheMountOfAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ToolExecutionException::class);
        $this->callTool('get_page', ['pageUid' => 4]);
    }

    #[Test]
    public function refusesPageWithoutReadPermissionOfAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ToolExecutionException::class);
        $this->callTool('get_page', ['pageUid' => 5]);
    }

    #[Test]
    public function returnsPageOutsideOfEveryMountForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('get_page', ['pageUid' => 4]);

        self::assertSame('Outside of the mount', $result['page']['title']);
    }

    #[Test]
    public function refusesALanguageTheEditorMayNotAccess(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_page', ['pageUid' => 2, 'languageId' => 1]);
    }

    #[Test]
    public function refusesTheContentForAnEditorWhoMayNotReadContentElements(): void
    {
        $this->allowEditorsOnlyPages();
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_page', ['pageUid' => 2]);
    }

    #[Test]
    public function returnsThePageWithoutContentForAnEditorWhoMayNotReadContentElements(): void
    {
        $this->allowEditorsOnlyPages();
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_page', ['pageUid' => 2, 'withContent' => false]);

        self::assertSame('About', $result['page']['title']);
        self::assertArrayNotHasKey('content', $result);
    }

    #[Test]
    public function returnsNoTranslationAndNoTranslationModeInTheDefaultLanguage(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_page', ['pageUid' => 2]);

        self::assertArrayNotHasKey('translation', $result);
        self::assertArrayNotHasKey('translationMode', $result);
    }

    #[Test]
    public function returnsThePageTranslationAndTheTranslationModeOfALanguage(): void
    {
        $this->loginAs(self::ADMIN);
        $untranslated = $this->callTool('get_page', ['pageUid' => 2, 'languageId' => 1]);

        $pageTranslation = $this->callTool('localize_record', ['table' => 'pages', 'uid' => 2, 'languageId' => 1]);
        $this->callTool('localize_record', ['table' => 'tt_content', 'uid' => 1, 'languageId' => 1]);
        $connected = $this->callTool('get_page', ['pageUid' => 2, 'languageId' => 1]);

        $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'fields' => ['sys_language_uid' => 1, 'header' => 'Free']]
        );
        $mixed = $this->callTool('get_page', ['pageUid' => 2, 'languageId' => 1]);

        self::assertNull($untranslated['translation']);
        self::assertSame('empty', $untranslated['translationMode']);
        self::assertSame((int)$pageTranslation['record']['uid'], (int)$connected['translation']['uid']);
        self::assertSame('About', $connected['page']['title']);
        self::assertSame('connected', $connected['translationMode']);
        self::assertSame('mixed', $mixed['translationMode']);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function deletedPageDataProvider(): array
    {
        return [
            'editor, page in the mount' => [self::EDITOR, 3, self::DELETED_PAGE],
            'editor, page outside of the mount' => [self::EDITOR, 4, self::MISSING_PAGE],
            'editor, page without read permission' => [self::EDITOR, 5, self::MISSING_PAGE],
            'administrator, page outside of every mount' => [self::ADMIN, 4, self::DELETED_PAGE],
        ];
    }

    #[Test]
    #[DataProvider('deletedPageDataProvider')]
    public function explainsADeletedPageOnlyToAUserWhoCouldReadIt(int $user, int $pageUid, int $code): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['deleted' => 1],
            ['uid' => $pageUid]
        );
        $this->loginAs($user);

        $this->expectExceptionCode($code);
        $this->callTool('get_page', ['pageUid' => $pageUid]);
    }

    private function allowEditorsOnlyPages(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['tables_select' => 'pages', 'tables_modify' => 'pages'],
            ['uid' => self::EDITOR_GROUP]
        );
    }
}
