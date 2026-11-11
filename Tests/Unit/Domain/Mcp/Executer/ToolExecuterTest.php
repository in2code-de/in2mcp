<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\Executer;

use In2code\In2mcp\Domain\Mcp\Executer\ToolExecuter;
use In2code\In2mcp\Domain\Mcp\Tool\ToolInterface;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Tests\Unit\Fixtures\ArgumentTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ToolExecuterTest extends UnitTestCase
{
    private ClientGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new ClientGateway($this->createMock(SessionInterface::class));
    }

    #[Test]
    public function executeReturnsExceptionMessageAsErrorResult(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('execute')->willThrowException(new ToolExecutionException('Call "get_page" first', 1));

        $result = (new ToolExecuter($tool))->execute([], $this->gateway);

        self::assertTrue($result->isError);
        self::assertFalse($result->hasStructuredContent());
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame('Call "get_page" first', $result->content[0]->text);
    }

    #[Test]
    public function executeWrapsListIntoObjectForStructuredContent(): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], [['uid' => 1], ['uid' => 2]])))
            ->execute([], $this->gateway);

        self::assertFalse($result->isError);
        self::assertSame(['result' => [['uid' => 1], ['uid' => 2]]], $result->structuredContent);
    }

    #[Test]
    public function executeKeepsAssociativeArrayAsStructuredContent(): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], ['uid' => 1, 'title' => 'Home'])))
            ->execute([], $this->gateway);

        self::assertSame(['uid' => 1, 'title' => 'Home'], $result->structuredContent);
    }

    #[Test]
    public function executeWrapsEmptyListIntoObject(): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], [])))->execute([], $this->gateway);

        self::assertSame(['result' => []], $result->structuredContent);
    }

    public static function scalarResultDataProvider(): array
    {
        return [
            'string' => ['Cache cleared'],
            'integer' => [42],
            'boolean' => [true],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('scalarResultDataProvider')]
    public function executeLeavesOutStructuredContentForScalarResult(mixed $toolResult): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], $toolResult)))->execute([], $this->gateway);

        self::assertFalse($result->isError);
        self::assertFalse($result->hasStructuredContent());
    }

    #[Test]
    public function executeConvertsNestedObjectArgumentsToArrays(): void
    {
        $fields = new stdClass();
        $fields->title = 'Home';
        $fields->nested = new stdClass();
        $fields->nested->value = 1;
        $list = [new stdClass()];

        $tool = $this->createMock(ToolInterface::class);
        $tool->expects(self::once())
            ->method('execute')
            ->with(['fields' => ['title' => 'Home', 'nested' => ['value' => 1]], 'list' => [[]], 'uid' => 3])
            ->willReturn('ok');

        (new ToolExecuter($tool))->execute(['fields' => $fields, 'list' => $list, 'uid' => 3], $this->gateway);
    }
}
