<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\WorkspaceService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WorkspaceServiceTest extends UnitTestCase
{
    #[Test]
    public function getWorkspaceIdReadsTheWorkspaceAspect(): void
    {
        self::assertSame(3, $this->createSubject(3)->getWorkspaceId());
    }

    #[Test]
    public function getWorkspaceIdIsLiveWithoutAspect(): void
    {
        self::assertSame(WorkspaceService::LIVE_WORKSPACE, (new WorkspaceService(new Context()))->getWorkspaceId());
    }

    #[Test]
    public function overlayRecordReturnsTheRecordUnchangedInLive(): void
    {
        $record = ['uid' => 20, 'pid' => 9, 'header' => 'Live'];

        self::assertSame($record, $this->createSubject(0)->overlayRecord('tt_content', $record));
    }

    #[Test]
    public function overlayRecordsKeepsEveryRecordInLive(): void
    {
        $records = [5 => ['uid' => 20], 9 => ['uid' => 21]];

        self::assertSame([['uid' => 20], ['uid' => 21]], $this->createSubject(0)->overlayRecords('tt_content', $records));
    }

    private function createSubject(int $workspaceId): WorkspaceService
    {
        $context = new Context();
        $context->setAspect('workspace', new WorkspaceAspect($workspaceId));
        return new WorkspaceService($context);
    }
}
