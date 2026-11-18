<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\WorkspaceRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\WorkspaceService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Package\PackageManager;
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
        self::assertSame(WorkspaceService::LIVE_WORKSPACE, $this->createSubject(null)->getWorkspaceId());
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

        self::assertSame(
            [['uid' => 20], ['uid' => 21]],
            $this->createSubject(0)->overlayRecords('tt_content', $records)
        );
    }

    #[Test]
    public function workspacesCanNotBeListedWithoutTheExtension(): void
    {
        $this->expectExceptionCode(1756802404);
        $this->createSubject(0)->findAccessibleWorkspaces();
    }

    #[Test]
    public function workspaceCanNotBeSwitchedWithoutTheExtension(): void
    {
        $this->expectExceptionCode(1756802404);
        $this->createSubject(0)->switchWorkspace(1);
    }

    private function createSubject(?int $workspaceId): WorkspaceService
    {
        $context = new Context();
        if ($workspaceId !== null) {
            $context->setAspect('workspace', new WorkspaceAspect($workspaceId));
        }
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturn(false);

        return new WorkspaceService(
            $context,
            $packageManager,
            $this->createStub(WorkspaceRepository::class),
            $this->createStub(BackendUserService::class)
        );
    }
}
