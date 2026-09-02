<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool;

use In2code\In2mcp\Exception\ToolExecutionException;

abstract class AbstractTool implements ToolInterface
{
    public function getParameters(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolExecutionException
     */
    protected function getIntArgument(array $arguments, string $name): int
    {
        return (int)$this->getArgument($arguments, $name);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolExecutionException
     */
    protected function getStringArgument(array $arguments, string $name): string
    {
        return (string)$this->getArgument($arguments, $name);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolExecutionException
     */
    protected function getArrayArgument(array $arguments, string $name): array
    {
        $value = $this->getArgument($arguments, $name);
        if (is_array($value) === false) {
            throw new ToolExecutionException(
                'Argument "' . $name . '" of tool "' . $this->getName() . '" must be an object or a list',
                1756800400
            );
        }
        return $value;
    }

    /**
     * Reads an argument and falls back to the configured default value. A missing argument without a default is
     * a client error, so it is reported as such instead of silently using null.
     *
     * @param array<string, mixed> $arguments
     * @throws ToolExecutionException
     */
    protected function getArgument(array $arguments, string $name): mixed
    {
        if (array_key_exists($name, $arguments) && $arguments[$name] !== null) {
            return $arguments[$name];
        }

        $parameter = $this->getParameters()[$name] ?? null;
        if ($parameter === null) {
            throw new ToolExecutionException(
                'Tool "' . $this->getName() . '" has no parameter "' . $name . '"',
                1756800404
            );
        }

        if (array_key_exists('default', $parameter)) {
            return $parameter['default'];
        }

        throw new ToolExecutionException(
            'Required argument "' . $name . '" is missing for tool "' . $this->getName() . '"',
            1756800408
        );
    }
}
