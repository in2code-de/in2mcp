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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ToolExecuterTest extends UnitTestCase
{
    private ClientGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new ClientGateway(self::createStub(SessionInterface::class));
    }

    #[Test]
    public function executeReturnsExceptionMessageAsErrorResult(): void
    {
        $tool = self::createStub(ToolInterface::class);
        $tool->method('execute')->willThrowException(new ToolExecutionException('Call "get_page" first', 1));

        $result = (new ToolExecuter($tool, new NullLogger()))->execute([], $this->gateway);

        self::assertTrue($result->isError);
        self::assertArrayNotHasKey('structuredContent', $result->jsonSerialize());
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertSame('Call "get_page" first', $result->content[0]->text);
    }

    #[Test]
    public function executeWrapsListIntoObjectForStructuredContent(): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], [['uid' => 1], ['uid' => 2]]), new NullLogger()))
            ->execute([], $this->gateway);

        self::assertFalse($result->isError);
        self::assertSame(['result' => [['uid' => 1], ['uid' => 2]]], $result->structuredContent);
    }

    #[Test]
    public function executeKeepsAssociativeArrayAsStructuredContent(): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], ['uid' => 1, 'title' => 'Home']), new NullLogger()))
            ->execute([], $this->gateway);

        self::assertSame(['uid' => 1, 'title' => 'Home'], $result->structuredContent);
    }

    #[Test]
    public function executeWrapsEmptyListIntoObject(): void
    {
        $result = (new ToolExecuter(new ArgumentTool([], []), new NullLogger()))->execute([], $this->gateway);

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
        $result = (new ToolExecuter(new ArgumentTool([], $toolResult), new NullLogger()))->execute([], $this->gateway);

        self::assertFalse($result->isError);
        self::assertArrayNotHasKey('structuredContent', $result->jsonSerialize());
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

        (new ToolExecuter($tool, new NullLogger()))->execute(
            ['fields' => $fields, 'list' => $list, 'uid' => 3],
            $this->gateway
        );
    }

    #[Test]
    public function executeHidesTheMessageOfAnUnexpectedException(): void
    {
        $tool = self::createStub(ToolInterface::class);
        $tool->method('getName')->willReturn('get_record');
        $tool->method('execute')->willThrowException(
            new RuntimeException('SQLSTATE[42S22]: Column not found in /var/www/html/vendor', 1)
        );

        $result = (new ToolExecuter($tool, new NullLogger()))->execute([], $this->gateway);

        self::assertTrue($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        self::assertStringNotContainsString('SQLSTATE', $result->content[0]->text);
        self::assertStringContainsString('Internal error while running "get_record"', $result->content[0]->text);
    }

    #[Test]
    public function executeLogsAnUnexpectedExceptionUnderTheReferenceTheClientGets(): void
    {
        $tool = self::createStub(ToolInterface::class);
        $tool->method('getName')->willReturn('get_record');
        $tool->method('execute')->willThrowException(new RuntimeException('SQLSTATE[42S22]', 1));
        $loggedMessage = '';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(
            static function (string $message) use (&$loggedMessage): void {
                $loggedMessage = $message;
            }
        );

        $result = (new ToolExecuter($tool, $logger))->execute([], $this->gateway);

        self::assertInstanceOf(TextContent::class, $result->content[0]);
        preg_match('~reference ([0-9a-f]+)~', $result->content[0]->text, $matches);
        self::assertStringContainsString('reference ' . $matches[1], $loggedMessage);
        self::assertStringContainsString('SQLSTATE[42S22]', $loggedMessage);
    }
}
