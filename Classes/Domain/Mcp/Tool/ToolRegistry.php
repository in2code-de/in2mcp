<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool;

use Traversable;

/**
 * Collects all tools that are tagged as in2mcp.tool by the service configuration
 */
readonly class ToolRegistry
{
    /**
     * @param Traversable<ToolInterface> $tools
     */
    public function __construct(private Traversable $tools)
    {
    }

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array
    {
        return iterator_to_array($this->tools, false);
    }
}
