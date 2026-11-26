<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool;

use In2code\In2mcp\Exception\ToolExecutionException;

abstract class AbstractTool implements ToolInterface
{
    /**
     * The defaults are the careful ones: a tool that does not say otherwise is taken as one that may destroy
     * something, so a client asks before running it.
     */
    protected const READ_ONLY = false;
    protected const DESTRUCTIVE = true;
    protected const OPEN_WORLD = false;

    protected const LINK_REPORT_DESCRIPTION = ' Every written link field is described in "links": whether its page'
        . ' or file exists for this backend user, and where it points to.';

    public function getParameters(): array
    {
        return [];
    }

    public function isReadOnly(): bool
    {
        return static::READ_ONLY;
    }

    public function isDestructive(): bool
    {
        return static::READ_ONLY === false && static::DESTRUCTIVE;
    }

    public function isOpenWorld(): bool
    {
        return static::OPEN_WORLD;
    }

    /**
     * Definition of the parameter that reduces a returned record to some of its fields
     *
     * @return array<string, mixed>
     */
    protected function getReturnFieldsParameter(string $example): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Fields to return, for example ' . $example . '. uid and pid are always returned.'
                . ' Leave it out to get every field.',
            'default' => [],
        ];
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
