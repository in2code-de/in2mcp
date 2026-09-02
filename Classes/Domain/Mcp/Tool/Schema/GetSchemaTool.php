<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Schema;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\TcaService;

/**
 * Every TYPO3 installation has its own page types, content types and fields. This tool reports what exists
 * here, so a client does not have to guess field names when writing records.
 */
class GetSchemaTool extends AbstractTool
{
    public function __construct(private readonly TcaService $tcaService)
    {
    }

    public function getName(): string
    {
        return 'get_schema';
    }

    public function getDescription(): string
    {
        return 'Returns the page types, content element types and the fields of pages and content elements that'
            . ' exist in this TYPO3 installation. Call this before writing records, because the available types'
            . ' and fields differ from installation to installation.';
    }

    public function getParameters(): array
    {
        return [
            'withFields' => [
                'type' => 'boolean',
                'description' => 'Whether the field definitions of pages and content elements are included',
                'default' => true,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $schema = [
            'pageTypes' => $this->tcaService->getPageTypes(),
            'contentTypes' => $this->tcaService->getContentTypes(),
        ];

        if ((bool)$this->getArgument($arguments, 'withFields')) {
            $schema['fields'] = [
                PageRepository::TABLE_NAME => $this->tcaService->getFields(PageRepository::TABLE_NAME),
                ContentRepository::TABLE_NAME => $this->tcaService->getFields(ContentRepository::TABLE_NAME),
            ];
        }

        return $schema;
    }
}
