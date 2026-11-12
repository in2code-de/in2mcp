<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\BackendUserActivityRepository;
use In2code\In2mcp\Domain\Repository\RecentChangeRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\LastEditorService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LastEditorServiceTest extends UnitTestCase
{
    #[Test]
    public function pickNewestEditorsTakesTheFirstEntryOfEachPage(): void
    {
        $historyEntries = [
            ['tablename' => 'tt_content', 'recuid' => 70, 'userid' => 3],
            ['tablename' => 'pages', 'recuid' => 10, 'userid' => 4],
            ['tablename' => 'pages', 'recuid' => 11, 'userid' => 5],
            ['tablename' => 'pages', 'recuid' => 20, 'userid' => 6],
        ];
        $keysByRecord = [
            'pages' => [10 => 10, 11 => 10, 20 => 20],
            'tt_content' => [70 => 10],
        ];

        self::assertSame(
            [10 => 3, 20 => 6],
            $this->createSubject(true)->pickNewestEditors($historyEntries, $keysByRecord)
        );
    }

    #[Test]
    public function pickNewestEditorsIgnoresUnknownRecords(): void
    {
        $historyEntries = [['tablename' => 'sys_category', 'recuid' => 10, 'userid' => 3]];

        self::assertSame([], $this->createSubject(true)->pickNewestEditors($historyEntries, ['pages' => [10 => 10]]));
    }

    #[Test]
    public function editorsAreNotFoundForNonAdministrators(): void
    {
        $subject = $this->createSubject(false);

        self::assertFalse($subject->isAvailable());
        self::assertSame([], $subject->findEditorsOfPages([10 => 1000]));
        self::assertSame([], $subject->findEditorsOfContent([70 => 1000]));
    }

    private function createSubject(bool $isAdmin): LastEditorService
    {
        $backendUserService = $this->createStub(BackendUserService::class);
        $backendUserService->method('isAdmin')->willReturn($isAdmin);

        return new LastEditorService(
            $this->createStub(BackendUserActivityRepository::class),
            $this->createStub(RecentChangeRepository::class),
            $backendUserService
        );
    }
}
