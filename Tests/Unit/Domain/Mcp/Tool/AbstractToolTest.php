<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\Tool;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Tests\Unit\Fixtures\ArgumentTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AbstractToolTest extends UnitTestCase
{
    private ArgumentTool $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ArgumentTool([
            'uid' => ['type' => 'integer', 'required' => true],
            'limit' => ['type' => 'integer', 'default' => 20],
            'fields' => ['type' => 'object', 'default' => 'no object'],
            'nullable' => ['type' => 'string', 'default' => null],
        ]);
    }

    #[Test]
    public function getArgumentReturnsGivenValue(): void
    {
        self::assertSame(5, $this->subject->readArgument(['limit' => 5], 'limit'));
    }

    #[Test]
    public function getArgumentFallsBackToDefaultForMissingArgument(): void
    {
        self::assertSame(20, $this->subject->readArgument([], 'limit'));
    }

    #[Test]
    public function getArgumentFallsBackToDefaultForNullArgument(): void
    {
        self::assertSame(20, $this->subject->readArgument(['limit' => null], 'limit'));
    }

    #[Test]
    public function getArgumentReturnsNullDefault(): void
    {
        self::assertNull($this->subject->readArgument([], 'nullable'));
    }

    #[Test]
    public function getArgumentKeepsFalsyValues(): void
    {
        self::assertSame(0, $this->subject->readArgument(['limit' => 0], 'limit'));
        self::assertSame('', $this->subject->readArgument(['nullable' => ''], 'nullable'));
    }

    #[Test]
    public function getArgumentThrowsForMissingRequiredArgument(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionCode(1756800408);
        $this->subject->readArgument([], 'uid');
    }

    #[Test]
    public function getArgumentThrowsForUnknownParameter(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionCode(1756800404);
        $this->subject->readArgument([], 'unknown');
    }

    #[Test]
    public function getIntArgumentCastsValue(): void
    {
        self::assertSame(42, $this->subject->readIntArgument(['uid' => '42'], 'uid'));
    }

    #[Test]
    public function getStringArgumentCastsValue(): void
    {
        self::assertSame('42', $this->subject->readStringArgument(['uid' => 42], 'uid'));
    }

    #[Test]
    public function getArrayArgumentReturnsArray(): void
    {
        self::assertSame(['title' => 'x'], $this->subject->readArrayArgument(['fields' => ['title' => 'x']], 'fields'));
    }

    #[Test]
    public function getArrayArgumentThrowsForNonArrayValue(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionCode(1756800400);
        $this->subject->readArrayArgument([], 'fields');
    }

    #[Test]
    public function getParametersIsEmptyByDefault(): void
    {
        $tool = new class () extends AbstractTool {
            public function getName(): string
            {
                return 'empty';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function execute(array $arguments): mixed
            {
                return null;
            }
        };

        self::assertSame([], $tool->getParameters());
    }
}
