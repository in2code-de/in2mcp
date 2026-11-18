<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Workspace;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

final class WorkspaceToolsTest extends AbstractFunctionalTestCase
{
    private const LIVE_WORKSPACE = 0;
    private const DRAFT_WORKSPACE = 1;
    private const FOREIGN_WORKSPACE = 2;

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/Workspaces.csv');
    }

    #[Test]
    public function getWorkspacesListsLiveAndTheWorkspacesOfAMember(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('get_workspaces');

        self::assertSame(0, $result['currentWorkspace']);
        self::assertSame([0, self::DRAFT_WORKSPACE], array_column($result['workspaces'], 'uid'));
    }

    #[Test]
    public function switchWorkspaceRefusesAWorkspaceTheEditorIsNoMemberOf(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('switch_workspace', ['workspaceId' => self::FOREIGN_WORKSPACE]);
    }

    #[Test]
    public function changeInAWorkspaceLeavesLiveUntouched(): void
    {
        $this->loginAs(self::EDITOR);
        $result = $this->callTool('switch_workspace', ['workspaceId' => self::DRAFT_WORKSPACE]);
        self::assertSame(self::DRAFT_WORKSPACE, $result['currentWorkspace']);

        $this->callTool('update_content_element', ['contentUid' => 1, 'fields' => ['header' => 'Draft header']]);

        self::assertSame(
            'Draft header',
            $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1])['record']['header']
        );
        self::assertSame('First element', $this->getLiveHeader(1));
    }

    #[Test]
    public function newElementInAWorkspaceIsShownThereOnly(): void
    {
        $this->loginAs(self::EDITOR);
        $this->callTool('switch_workspace', ['workspaceId' => self::DRAFT_WORKSPACE]);

        $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'fields' => ['header' => 'Draft element']]
        );

        $headers = array_column($this->callTool('get_page', ['pageUid' => 2])['content']['colPos_0'], 'header');
        self::assertSame(['First element', 'Second element', 'Draft element'], $headers);
        $this->callTool('switch_workspace', ['workspaceId' => 0]);
        $headers = array_column($this->callTool('get_page', ['pageUid' => 2])['content']['colPos_0'], 'header');
        self::assertSame(['First element', 'Second element'], $headers);
    }

    #[Test]
    public function deletionInAWorkspaceHidesTheRecordThereOnly(): void
    {
        $this->loginAs(self::EDITOR);
        $this->callTool('switch_workspace', ['workspaceId' => self::DRAFT_WORKSPACE]);

        $this->callTool('delete_record', ['table' => 'tt_content', 'uid' => 2]);

        $records = $this->callTool('find_records', ['table' => 'tt_content'])['records'];
        $uids = array_map('intval', array_column($records, 'uid'));
        self::assertNotContains(2, $uids);
        self::assertSame('Second element', $this->getLiveHeader(2));
    }

    #[Test]
    public function draftsOfAWorkspaceTheEditorIsNoMemberOfStayInvisible(): void
    {
        $this->createDraftsInTheForeignWorkspace();

        foreach ([self::LIVE_WORKSPACE, self::DRAFT_WORKSPACE] as $workspaceId) {
            $this->loginAs(self::EDITOR);
            $this->callTool('switch_workspace', ['workspaceId' => $workspaceId]);

            $record = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1])['record'];
            $headers = array_column($this->callTool('get_page', ['pageUid' => 2])['content']['colPos_0'], 'header');
            $found = $this->callTool('find_records', ['table' => 'tt_content', 'pid' => 2]);

            self::assertSame('First element', $record['header']);
            self::assertSame(['First element', 'Second element'], $headers);
            self::assertSame(['First element', 'Second element'], array_column($found['records'], 'header'));
        }
    }

    private function createDraftsInTheForeignWorkspace(): void
    {
        $this->loginAs(self::ADMIN);
        $this->callTool('switch_workspace', ['workspaceId' => self::FOREIGN_WORKSPACE]);
        $this->callTool('update_content_element', ['contentUid' => 1, 'fields' => ['header' => 'Foreign draft']]);
        $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'fields' => ['header' => 'Foreign element']]
        );
        self::assertSame(
            'Foreign draft',
            $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1])['record']['header']
        );
    }

    private function getLiveHeader(int $uid): string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        return (string)$queryBuilder->select('header')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    't3ver_wsid',
                    $queryBuilder->createNamedParameter(self::LIVE_WORKSPACE, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchOne();
    }
}
