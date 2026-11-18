<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\RateLimiter;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Reads, counts and writes the limit of one client under an exclusive lock. Without it, requests sent at the same
 * moment all read the counter before any of them has raised it, and all of them pass the limit.
 *
 * The lock of the Symfony limiter needs symfony/lock, which TYPO3 does not ship, so the locking API of the core is
 * used instead.
 */
readonly class LockedLimiter implements LimiterInterface
{
    public function __construct(
        private LimiterInterface $limiter,
        private LockingStrategyInterface $lock,
    ) {
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        return $this->locked(fn (): Reservation => $this->limiter->reserve($tokens, $maxTime));
    }

    public function consume(int $tokens = 1): RateLimit
    {
        return $this->locked(fn (): RateLimit => $this->limiter->consume($tokens));
    }

    public function reset(): void
    {
        $this->locked(fn (): null => $this->limiter->reset());
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $this->lock->acquire();
        try {
            return $callback();
        } finally {
            $this->lock->release();
        }
    }
}
