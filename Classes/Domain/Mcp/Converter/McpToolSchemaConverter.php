<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Converter;

use In2code\In2mcp\Domain\Mcp\Tool\ToolInterface;
use Mcp\Schema\Tool;

/**
 * Converts the parameter definition of a tool into a MCP tool definition with a json schema
 */
class McpToolSchemaConverter
{
    public function convert(ToolInterface $tool): Tool
    {
        return Tool::fromArray([
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'inputSchema' => $this->getInputSchema($tool),
        ]);
    }

    protected function getInputSchema(ToolInterface $tool): array
    {
        $properties = [];
        $required = [];
        foreach ($tool->getParameters() as $name => $parameter) {
            $properties[$name] = [
                'type' => $parameter['type'] ?? 'string',
                'description' => $parameter['description'] ?? '',
            ];
            if (array_key_exists('default', $parameter)) {
                $properties[$name]['default'] = $parameter['default'];
            }
            if (($parameter['enum'] ?? []) !== []) {
                $properties[$name]['enum'] = $parameter['enum'];
            }
            if (($parameter['items'] ?? []) !== []) {
                $properties[$name]['items'] = $parameter['items'];
            }
            if ($parameter['required'] ?? false) {
                $required[] = $name;
            }
        }

        $inputSchema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $inputSchema['required'] = $required;
        }
        return $inputSchema;
    }
}
