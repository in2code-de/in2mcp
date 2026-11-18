<?php

declare(strict_types=1);

namespace In2code\In2mcp\Exception;

use Exception;

/**
 * The message of these exceptions is written for the MCP client and tells it what to do instead. Every other
 * exception reaches the client only as a reference into the log, because its message can carry internals.
 */
abstract class ClientFacingException extends Exception
{
}
