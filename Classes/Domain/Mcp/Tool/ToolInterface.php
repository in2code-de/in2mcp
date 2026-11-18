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
     * Hint for the client that the tool changes nothing. A client may run such a tool without asking.
     */
    public function isReadOnly(): bool;

    /**
     * Hint for the client that the tool may overwrite or delete something, so it should ask before running it
     */
    public function isDestructive(): bool;

    /**
     * Hint for the client that the tool reaches outside of this installation
     */
    public function isOpenWorld(): bool;

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): mixed;
}
