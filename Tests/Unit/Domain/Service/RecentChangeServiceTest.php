<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Repository\RecentChangeRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\RecentChangeService;
use In2code\In2mcp\Domain\Service\TcaService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecentChangeServiceTest extends UnitTestCase
{
    private RecentChangeService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RecentChangeService(
            $this->createStub(RecentChangeRepository::class),
            $this->createStub(PageRepository::class),
            $this->createStub(BackendUserService::class),
            $this->createStub(TcaService::class)
        );
    }

    #[Test]
    public function rankByLastChangeUsesNewestOfPageAndContentChange(): void
    {
        $ranking = $this->subject->rankByLastChange([10 => 100, 20 => 300], [10 => 500, 30 => 200], 10);

        self::assertSame([10, 20, 30], array_keys($ranking));
        self::assertSame(['lastChanged' => 500, 'pageChanged' => 100, 'contentChanged' => 500], $ranking[10]);
        self::assertSame(['lastChanged' => 300, 'pageChanged' => 300, 'contentChanged' => null], $ranking[20]);
        self::assertSame(['lastChanged' => 200, 'pageChanged' => null, 'contentChanged' => 200], $ranking[30]);
    }

    #[Test]
    public function rankByLastChangeCutsAtLimit(): void
    {
        $ranking = $this->subject->rankByLastChange([1 => 10, 2 => 20], [3 => 30, 4 => 40], 2);

        self::assertSame([4, 3], array_keys($ranking));
    }

    #[Test]
    public function rankByLastChangeOrdersEqualTimesByNewerUid(): void
    {
        $ranking = $this->subject->rankByLastChange([5 => 100, 7 => 100], [], 10);

        self::assertSame([7, 5], array_keys($ranking));
    }

    #[Test]
    public function rankByLastChangeReturnsNothingWithoutChanges(): void
    {
        self::assertSame([], $this->subject->rankByLastChange([], [], 10));
    }
}
