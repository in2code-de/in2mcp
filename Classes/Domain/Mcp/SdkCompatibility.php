<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp;

use Mcp\Capability\Formatter\ToolResultFormatter as LegacyToolResultFormatter;
use Mcp\Schema\Enum\StructuredContent;
use Mcp\Server as LegacyServer;
use Mcp\Server\Builder;
use Mcp\Server\Capability\Formatter\ToolResultFormatter;
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ServerRequestInterface;

// mcp/sdk 0.10 needs reflection-docblock ^6, TYPO3 13 stays on 0.9 - remove when TYPO3 13 support is dropped
class SdkCompatibility
{
    protected const PRINCIPAL_ATTRIBUTE_CONSTANT = StreamableHttpTransport::class . '::PRINCIPAL_ATTRIBUTE';

    public static function getServerBuilder(): Builder
    {
        return class_exists(Server::class) ? Server::builder() : LegacyServer::builder();
    }

    public static function getToolResultFormatter(): ToolResultFormatter|LegacyToolResultFormatter
    {
        return class_exists(ToolResultFormatter::class) ? new ToolResultFormatter() : new LegacyToolResultFormatter();
    }

    public static function getAbsentStructuredContent(): ?StructuredContent
    {
        return enum_exists(StructuredContent::class) ? StructuredContent::Absent : null;
    }

    public static function withPrincipal(ServerRequestInterface $request, string $principal): ServerRequestInterface
    {
        if (defined(self::PRINCIPAL_ATTRIBUTE_CONSTANT)) {
            $request = $request->withAttribute(constant(self::PRINCIPAL_ATTRIBUTE_CONSTANT), $principal);
        }
        return $request;
    }
}
