<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Executer;

use In2code\In2mcp\Domain\Mcp\SdkCompatibility;
use In2code\In2mcp\Domain\Mcp\Tool\ToolInterface;
use In2code\In2mcp\Exception\ClientFacingException;
use Mcp\Schema\Enum\StructuredContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * The boundary between a tool and the protocol, so like the middleware it is allowed to log: an unexpected error
 * is written to the log and reaches the client only as a reference.
 */
class ToolExecuter implements ToolHandlerInterface
{
    private const REFERENCE_BYTES = 6;

    public function __construct(
        private readonly ToolInterface $tool,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): CallToolResult
    {
        try {
            $result = $this->tool->execute($this->convertObjectsToArrays($arguments));
        } catch (Throwable $exception) {
            // The client is the addressee of a tool error, so it is returned as an error result instead of
            // letting the request fail with a protocol error.
            return new CallToolResult(
                SdkCompatibility::getToolResultFormatter()->format($this->getClientMessage($exception)),
                true
            );
        }

        return new CallToolResult(
            SdkCompatibility::getToolResultFormatter()->format($result),
            structuredContent: $this->getStructuredContent($result)
        );
    }

    /**
     * Messages of in2mcp are written for the client. Any other message - a database error, a path, an address of
     * the internal network - stays in the log.
     */
    protected function getClientMessage(Throwable $exception): string
    {
        if ($exception instanceof ClientFacingException) {
            return $exception->getMessage();
        }

        $reference = bin2hex(random_bytes(self::REFERENCE_BYTES));
        $this->logger->error(
            'MCP: Tool "' . $this->tool->getName() . '" failed (reference ' . $reference . '): '
            . $exception->getMessage(),
            ['exception' => $exception]
        );
        return 'Internal error while running "' . $this->tool->getName() . '". The details are in the log of'
            . ' TYPO3 under the reference ' . $reference . '.';
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
