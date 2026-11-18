<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

/**
 * Number of pages one page tree may still hold. The tree is built page by page, so the budget is shared by every
 * level and stops the queries as well as the answer.
 */
class PageTreeBudget
{
    private bool $truncated = false;

    public function __construct(private int $remainingPages)
    {
    }

    public function take(): bool
    {
        $isAvailable = $this->remainingPages > 0;
        $this->remainingPages -= $isAvailable ? 1 : 0;
        $this->truncated = $this->truncated || $isAvailable === false;
        return $isAvailable;
    }

    public function getRemainingPages(): int
    {
        return $this->remainingPages;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}
