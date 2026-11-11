<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\Converter;

use In2code\In2mcp\Domain\Mcp\Converter\McpToolSchemaConverter;
use In2code\In2mcp\Tests\Unit\Fixtures\ArgumentTool;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class McpToolSchemaConverterTest extends UnitTestCase
{
    private McpToolSchemaConverter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new McpToolSchemaConverter();
    }

    #[Test]
    public function convertTakesOverNameAndDescription(): void
    {
        $tool = $this->subject->convert(new ArgumentTool());

        self::assertSame('argument_tool', $tool->name);
        self::assertSame('Tool for tests', $tool->description);
    }

    #[Test]
    public function convertBuildsObjectSchemaWithoutRequiredListForToolWithoutParameters(): void
    {
        $inputSchema = $this->subject->convert(new ArgumentTool())->inputSchema;

        self::assertSame('object', $inputSchema['type']);
        self::assertArrayNotHasKey('required', $inputSchema);
    }

    #[Test]
    public function convertBuildsPropertiesFromParameterDefinition(): void
    {
        $inputSchema = $this->subject->convert(new ArgumentTool([
            'uid' => ['type' => 'integer', 'description' => 'Uid', 'required' => true],
            'limit' => ['type' => 'integer', 'description' => 'Limit', 'default' => 20],
            'mode' => ['enum' => ['a', 'b']],
            'uids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'optional' => ['required' => false],
        ]))->inputSchema;

        self::assertEquals(
            [
                'uid' => ['type' => 'integer', 'description' => 'Uid'],
                'limit' => ['type' => 'integer', 'description' => 'Limit', 'default' => 20],
                'mode' => ['type' => 'string', 'description' => '', 'enum' => ['a', 'b']],
                'uids' => ['type' => 'array', 'description' => '', 'items' => ['type' => 'integer']],
                'optional' => ['type' => 'string', 'description' => ''],
            ],
            (array)$inputSchema['properties']
        );
        self::assertSame(['uid'], $inputSchema['required']);
    }

    #[Test]
    public function convertKeepsNullDefault(): void
    {
        $inputSchema = $this->subject->convert(new ArgumentTool([
            'language' => ['type' => 'integer', 'default' => null],
        ]))->inputSchema;

        $properties = (array)$inputSchema['properties'];
        self::assertArrayHasKey('default', (array)$properties['language']);
        self::assertNull(((array)$properties['language'])['default']);
    }

    #[Test]
    public function convertLeavesOutEmptyEnumAndItems(): void
    {
        $inputSchema = $this->subject->convert(new ArgumentTool([
            'value' => ['enum' => [], 'items' => []],
        ]))->inputSchema;

        $properties = (array)$inputSchema['properties'];
        self::assertSame(['type' => 'string', 'description' => ''], (array)$properties['value']);
    }
}
