<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Fixtures;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;

class ArgumentTool extends AbstractTool
{
    /**
     * @param array<string, array> $parameters
     */
    public function __construct(private readonly array $parameters = [], private readonly mixed $result = null)
    {
    }

    public function getName(): string
    {
        return 'argument_tool';
    }

    public function getDescription(): string
    {
        return 'Tool for tests';
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function execute(array $arguments): mixed
    {
        return $this->result;
    }

    public function readArgument(array $arguments, string $name): mixed
    {
        return $this->getArgument($arguments, $name);
    }

    public function readIntArgument(array $arguments, string $name): int
    {
        return $this->getIntArgument($arguments, $name);
    }

    public function readStringArgument(array $arguments, string $name): string
    {
        return $this->getStringArgument($arguments, $name);
    }

    public function readArrayArgument(array $arguments, string $name): array
    {
        return $this->getArrayArgument($arguments, $name);
    }
}
