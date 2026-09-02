<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool;

/**
 * A tool that is offered to MCP clients. Every implementation is collected automatically by the ToolRegistry.
 */
interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * Parameter definition of the tool, used to build the json schema of the MCP tool.
     *
     * @return array<string, array{type?: string, description?: string, required?: bool, default?: mixed, enum?: array, items?: array}>
     */
    public function getParameters(): array;

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): mixed;
}
