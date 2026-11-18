<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class GetPageToolTest extends AbstractFunctionalTestCase
{
    private const EDITOR_GROUP = 1;

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

    private function allowEditorsOnlyPages(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update(
            'be_groups',
            ['tables_select' => 'pages', 'tables_modify' => 'pages'],
            ['uid' => self::EDITOR_GROUP]
        );
    }
}
