<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class PageToolsTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function pageTreeOfAnEditorHoldsOnlyReadablePagesOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_page_tree', ['pageUid' => 1]);

        self::assertSame([2, 3], array_column($result['tree'], 'uid'));
        self::assertFalse($result['truncated']);
    }

    #[Test]
    public function pageTreeOfAnAdministratorStartsAtTheRoot(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('get_page_tree', ['pageUid' => 0, 'depth' => 1]);

        self::assertSame([1, 4], array_column($result['tree'], 'uid'));
    }

    #[Test]
    public function pageTreeRefusesALanguageTheEditorMayNotAccess(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_page_tree', ['pageUid' => 1, 'languageId' => 1]);
    }

    #[Test]
    public function searchFindsOnlyPagesOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $titles = array_column($this->callTool('search_pages', ['searchTerm' => 'o']), 'title');

        self::assertContains('About', $titles);
        self::assertContains('Contact', $titles);
        self::assertNotContains('Outside of the mount', $titles);
        self::assertNotContains('Not readable for editors', $titles);
    }

    #[Test]
    public function pageTreeLeavesOutTheNavigationTitleForAnEditorWithoutTheFieldPermission(): void
    {
        $this->loginAs(self::EDITOR);

        $tree = $this->callTool('get_page_tree', ['pageUid' => 1])['tree'];

        self::assertArrayNotHasKey('nav_title', array_column($tree, null, 'uid')[3]);
    }

    #[Test]
    public function pageTreeContainsTheNavigationTitleForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $tree = $this->callTool('get_page_tree', ['pageUid' => 1])['tree'];

        self::assertSame('Navigation only', array_column($tree, null, 'uid')[3]['nav_title']);
    }

    #[Test]
    public function searchIgnoresExcludeFieldsAnEditorMayNotRead(): void
    {
        $this->loginAs(self::EDITOR);

        self::assertSame([], $this->callTool('search_pages', ['searchTerm' => 'Navigation only']));
        self::assertSame([], $this->callTool('search_pages', ['searchTerm' => 'Subline only']));
    }

    #[Test]
    public function searchFindsExcludeFieldsForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('search_pages', ['searchTerm' => 'Subline only']);

        self::assertSame([3], array_map('intval', array_column($result, 'uid')));
    }

    #[Test]
    public function getPageLeavesOutExcludeFieldsAnEditorMayNotRead(): void
    {
        $this->loginAs(self::EDITOR);

        $page = $this->callTool('get_page', ['pageUid' => 3, 'withContent' => false])['page'];

        self::assertSame('Contact', $page['title']);
        self::assertArrayNotHasKey('nav_title', $page);
        self::assertArrayNotHasKey('subtitle', $page);
    }

    #[Test]
    public function createPageAppendsAHiddenPageBehindTheLastSubpage(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('create_page', ['parentPageUid' => 1, 'title' => 'New page']);

        self::assertTrue($result['created']);
        self::assertSame(1, (int)$result['page']['pid']);
        self::assertSame(1, (int)$result['page']['hidden']);
        $tree = $this->callTool('get_page_tree', ['pageUid' => 1]);
        self::assertSame($result['pageUid'], (int)array_column($tree['tree'], 'uid')[array_key_last($tree['tree'])]);
    }

    #[Test]
    public function createPageAtTheStartPutsItInFrontOfAllSubpages(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'create_page',
            ['parentPageUid' => 1, 'title' => 'First page', 'position' => 'start']
        );

        $tree = $this->callTool('get_page_tree', ['pageUid' => 1]);
        self::assertSame($result['pageUid'], (int)$tree['tree'][0]['uid']);
    }

    #[Test]
    public function createPageRefusesAParentOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('create_page', ['parentPageUid' => 4, 'title' => 'Not allowed']);
    }

    #[Test]
    public function updatePageWritesTheTitle(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('update_page', ['pageUid' => 2, 'fields' => ['title' => 'About us']]);

        self::assertSame('About us', $result['page']['title']);
    }

    #[Test]
    public function updatePageRefusesAnExcludeFieldTheEditorMayNotWrite(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('update_page', ['pageUid' => 2, 'fields' => ['subtitle' => 'Not allowed']]);
    }

    #[Test]
    public function updatePageRefusesAPageOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('update_page', ['pageUid' => 4, 'fields' => ['title' => 'Not allowed']]);
    }

    #[Test]
    public function updatePageRefusesTheSortingField(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('update_page', ['pageUid' => 2, 'fields' => ['sorting' => 1]]);
    }

    #[Test]
    public function recentlyChangedPagesAreReturnedForAnEditor(): void
    {
        $this->loginAs(self::EDITOR);
        $this->callTool('update_page', ['pageUid' => 3, 'fields' => ['title' => 'Contact us']]);

        $result = $this->callTool('get_recently_changed_pages', ['pageUid' => 1]);

        self::assertSame('Contact us', $result[0]['title'] ?? $result['pages'][0]['title'] ?? null);
    }
}
