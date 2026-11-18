<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\System;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SystemToolsTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function getBackendUserDescribesTheEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_backend_user');

        self::assertSame('editor', $result['username']);
        self::assertFalse($result['isAdmin']);
        self::assertSame([0], $result['allowedLanguages']);
    }

    #[Test]
    public function getBackendUsersListsUsersForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $usernames = array_column($this->callTool('get_backend_users'), 'username');

        self::assertContains('editor', $usernames);
        self::assertNotContains('password', array_keys($this->callTool('get_backend_users')[0]));
    }

    #[Test]
    public function getBackendUsersRefusesAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_backend_users');
    }

    #[Test]
    public function getRecentChangesNamesTheEditorOfAChange(): void
    {
        $this->loginAs(self::EDITOR);
        $this->callTool('update_content_element', ['contentUid' => 1, 'fields' => ['header' => 'Changed']]);
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('get_recent_changes', ['table' => 'tt_content']);

        self::assertSame('editor', $result[0]['username']);
        self::assertSame(1, $result[0]['recordUid']);
        self::assertContains('header', $result[0]['changedFields']);
    }

    #[Test]
    public function getRecentChangesRefusesAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_recent_changes');
    }

    #[Test]
    public function clearCacheOfAPageInTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        self::assertTrue($this->callTool('clear_cache', ['scope' => '2'])['cleared']);
    }

    #[Test]
    public function clearCacheRefusesAPageOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('clear_cache', ['scope' => '4']);
    }

    #[Test]
    public function clearCacheRefusesAllCachesForAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('clear_cache', ['scope' => 'all']);
    }

    #[Test]
    public function clearCacheOfAllCachesForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        self::assertTrue($this->callTool('clear_cache', ['scope' => 'all'])['cleared']);
    }

    #[Test]
    public function schemaOverviewListsTheContentTypesOfAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_schema', ['pageUid' => 2]);

        self::assertSame(['header', 'text', 'textmedia'], array_column($result['contentTypes'], 'value'));
    }

    #[Test]
    public function schemaOverviewWithoutPageListsOnlyTheContentTypesOfTheGroups(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_schema');

        self::assertNotContains('html', array_column($result['contentTypes'], 'value'));
    }

    #[Test]
    public function schemaOverviewListsEveryContentTypeForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('get_schema', ['pageUid' => 2]);

        self::assertContains('html', array_column($result['contentTypes'], 'value'));
    }

    #[Test]
    public function schemaOfATableDescribesItsFields(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_schema', ['table' => 'tt_content', 'pageUid' => 2]);

        self::assertSame('tt_content', $result['table']);
        self::assertTrue($result['sortable']);
    }

    #[Test]
    public function schemaRefusesATableTheEditorMayNotRead(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_schema', ['table' => 'sys_category']);
    }

    #[Test]
    public function schemaRefusesAPageOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_schema', ['pageUid' => 4]);
    }

    #[Test]
    public function workspaceToolsExplainThatWorkspacesAreNotInstalled(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('get_workspaces');
    }
}
