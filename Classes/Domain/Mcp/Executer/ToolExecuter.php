<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Executer;

use In2code\In2mcp\Domain\Mcp\SdkCompatibility;
use In2code\In2mcp\Domain\Mcp\Tool\ToolInterface;
use Mcp\Schema\Enum\StructuredContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use stdClass;
use Throwable;

class ToolExecuter implements ToolHandlerInterface
{
    public function __construct(private readonly ToolInterface $tool)
    {
    }

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        try {
            $result = $this->tool->execute($this->convertObjectsToArrays($arguments));
        } catch (Throwable $exception) {
            // The client is the addressee of a tool error, so it is returned as an error result instead of
            // letting the request fail with a protocol error.
            return new CallToolResult(
                SdkCompatibility::getToolResultFormatter()->format($exception->getMessage()),
                true
            );
        }

        return new CallToolResult(
            SdkCompatibility::getToolResultFormatter()->format($result),
            structuredContent: $this->getStructuredContent($result)
        );
    }

    // The sdk keeps the json types of the arguments (nested objects are \stdClass), the tools expect arrays
    protected function convertObjectsToArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            $value = array_map(fn (mixed $item): mixed => $this->convertObjectsToArrays($item), $value);
        }
        return $value;
    }

    /**
     * The structured content of a tool result must be an object, while many tools return a list of records. Such
     * a list is wrapped into an object, everything that is not an array has no structure at all.
     */
    protected function getStructuredContent(mixed $result): array|StructuredContent|null
    {
        if (is_array($result) === false) {
            return SdkCompatibility::getAbsentStructuredContent();
        }

        if (array_is_list($result)) {
            return ['result' => $result];
        }

        return $result;
    }
}
