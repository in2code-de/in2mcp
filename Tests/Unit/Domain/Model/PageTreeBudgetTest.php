<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Model;

use In2code\In2mcp\Domain\Model\PageTreeBudget;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class PageTreeBudgetTest extends UnitTestCase
{
    #[Test]
    public function takeHandsOutExactlyTheBudget(): void
    {
        $budget = new PageTreeBudget(2);

        self::assertTrue($budget->take());
        self::assertTrue($budget->take());
        self::assertSame(0, $budget->getRemainingPages());
        self::assertFalse($budget->isTruncated());
    }

    #[Test]
    public function takeBeyondTheBudgetMarksTheTreeAsTruncated(): void
    {
        $budget = new PageTreeBudget(1);
        $budget->take();

        self::assertFalse($budget->take());
        self::assertTrue($budget->isTruncated());
        self::assertSame(0, $budget->getRemainingPages());
    }
}
