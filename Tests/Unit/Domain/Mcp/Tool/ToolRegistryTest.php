<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\Tool;

use ArrayIterator;
use Generator;
use In2code\In2mcp\Domain\Mcp\Tool\ToolRegistry;
use In2code\In2mcp\Tests\Unit\Fixtures\ArgumentTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ToolRegistryTest extends UnitTestCase
{
    #[Test]
    public function getToolsReturnsListOfAllTools(): void
    {
        $first = new ArgumentTool();
        $second = new ArgumentTool();

        $subject = new ToolRegistry(new ArrayIterator(['first' => $first, 'second' => $second]));

        self::assertSame([$first, $second], $subject->getTools());
    }

    #[Test]
    public function getToolsKeepsToolsWithDuplicateKeysOfAGenerator(): void
    {
        $first = new ArgumentTool();
        $second = new ArgumentTool();
        $generator = (static function () use ($first, $second): Generator {
            yield 0 => $first;
            yield 0 => $second;
        })();

        self::assertSame([$first, $second], (new ToolRegistry($generator))->getTools());
    }
}
