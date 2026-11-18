<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\RateLimiter;

use DateTimeImmutable;
use In2code\In2mcp\Domain\Mcp\RateLimiter\LockedLimiter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LockedLimiterTest extends UnitTestCase
{
    #[Test]
    public function consumeCountsUnderTheLock(): void
    {
        $calls = [];
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturnCallback(
            static function () use (&$calls): bool {
                $calls[] = 'acquire';
                return true;
            }
        );
        $lock->expects(self::once())->method('release')->willReturnCallback(
            static function () use (&$calls): bool {
                $calls[] = 'release';
                return true;
            }
        );
        $rateLimit = new RateLimit(19, new DateTimeImmutable(), true, 20);
        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->expects(self::once())->method('consume')->willReturnCallback(
            static function () use (&$calls, $rateLimit): RateLimit {
                $calls[] = 'consume';
                return $rateLimit;
            }
        );

        self::assertSame($rateLimit, (new LockedLimiter($limiter, $lock))->consume());
        self::assertSame(['acquire', 'consume', 'release'], $calls);
    }

    #[Test]
    public function lockIsReleasedWhenTheLimiterFails(): void
    {
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release')->willReturn(true);
        $limiter = self::createStub(LimiterInterface::class);
        $limiter->method('reset')->willThrowException(new RuntimeException('storage failed', 1756802500));

        $this->expectExceptionCode(1756802500);
        (new LockedLimiter($limiter, $lock))->reset();
    }
}
