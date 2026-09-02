<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Executer;

use In2code\In2mcp\Domain\Mcp\Tool\ToolInterface;
use Mcp\Capability\Formatter\ToolResultFormatter;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Throwable;

class ToolExecuter implements ToolHandlerInterface
{
    public function __construct(private readonly ToolInterface $tool)
    {
    }

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        try {
            $result = $this->tool->execute($arguments);
        } catch (Throwable $exception) {
            // The client is the addressee of a tool error, so it is returned as an error result instead of
            // letting the request fail with a protocol error.
            return new CallToolResult(
                (new ToolResultFormatter())->format($exception->getMessage()),
                true
            );
        }

        return new CallToolResult(
            (new ToolResultFormatter())->format($result),
            structuredContent: $this->getStructuredContent($result)
        );
    }

    /**
     * The structured content of a tool result must be an object, while many tools return a list of records. Such
     * a list is wrapped into an object, everything that is not an array has no structure at all.
     */
    protected function getStructuredContent(mixed $result): ?array
    {
        if (is_array($result) === false) {
            return null;
        }

        if (array_is_list($result)) {
            return ['result' => $result];
        }

        return $result;
    }
}
