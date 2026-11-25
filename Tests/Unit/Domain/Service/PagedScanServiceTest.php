<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\PagedScanService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PagedScanServiceTest extends UnitTestCase
{
    #[Test]
    public function collectSkipsDroppedRowsAndContinuesAfterTheLastRowLookedAt(): void
    {
        $rows = array_map(static fn (int $uid): array => ['uid' => $uid], range(1, 7));
        $findBatch = static fn (int $offset): array => array_slice($rows, $offset, 2);
        $keepOdd = static fn (array $row): ?array => $row['uid'] % 2 === 1 ? $row : null;
        $subject = new PagedScanService();

        $first = $subject->collect($findBatch, $keepOdd, 2, 0);
        $second = $subject->collect($findBatch, $keepOdd, 2, $first['nextOffset']);

        self::assertSame([1, 3], array_column($first['items'], 'uid'));
        self::assertSame(3, $first['nextOffset']);
        self::assertSame([5, 7], array_column($second['items'], 'uid'));
        self::assertSame(7, $second['nextOffset']);
        self::assertSame(['items' => [], 'nextOffset' => null], $subject->collect($findBatch, $keepOdd, 2, 7));
    }

    #[Test]
    public function collectEndsWithoutNextOffsetWhenTheRowsRunOut(): void
    {
        $subject = new PagedScanService();

        $result = $subject->collect(static fn (int $offset): array => [], static fn (array $row): array => $row, 5, 0);

        self::assertSame(['items' => [], 'nextOffset' => null], $result);
    }
}
