<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\PageRepository;

class SearchPagesTool extends AbstractTool
{
    private const MAX_LIMIT = 100;

    public function __construct(private readonly PageRepository $pageRepository)
    {
    }

    public function getName(): string
    {
        return 'search_pages';
    }

    public function getDescription(): string
    {
        return 'Searches pages by title, navigation title, subtitle or slug. Useful to find comparable pages,'
            . ' for example existing landing pages, before creating a new one.';
    }

    public function getParameters(): array
    {
        return [
            'searchTerm' => [
                'type' => 'string',
                'description' => 'Part of a title, navigation title, subtitle or slug',
                'required' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of pages returned, maximum ' . self::MAX_LIMIT,
                'default' => 20,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        return $this->pageRepository->search(
            $this->getStringArgument($arguments, 'searchTerm'),
            min($this->getIntArgument($arguments, 'limit'), self::MAX_LIMIT)
        );
    }
}
