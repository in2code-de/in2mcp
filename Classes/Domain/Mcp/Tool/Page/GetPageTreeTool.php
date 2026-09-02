<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\PageRepository;

class GetPageTreeTool extends AbstractTool
{
    private const MAX_DEPTH = 10;

    public function __construct(private readonly PageRepository $pageRepository)
    {
    }

    public function getName(): string
    {
        return 'get_page_tree';
    }

    public function getDescription(): string
    {
        return 'Returns the TYPO3 page tree as a nested structure. Start with pageUid 0 for the root of the'
            . ' installation. Only pages the backend user may see are returned.';
    }

    public function getParameters(): array
    {
        return [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Uid of the page to start from, 0 for the root of the page tree',
                'default' => 0,
            ],
            'depth' => [
                'type' => 'integer',
                'description' => 'How many levels below the start page are returned, maximum ' . self::MAX_DEPTH,
                'default' => 2,
            ],
            'languageId' => [
                'type' => 'integer',
                'description' => 'Language of the pages, 0 is the default language',
                'default' => 0,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $depth = min($this->getIntArgument($arguments, 'depth'), self::MAX_DEPTH);

        return [
            'pageUid' => $this->getIntArgument($arguments, 'pageUid'),
            'depth' => $depth,
            'tree' => $this->pageRepository->findTree(
                $this->getIntArgument($arguments, 'pageUid'),
                $depth,
                $this->getIntArgument($arguments, 'languageId')
            ),
        ];
    }
}
