<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\RecordAccessService;

class GetPageTreeTool extends AbstractTool
{
    protected const READ_ONLY = true;

    private const MAX_DEPTH = 10;
    private const MAX_PAGES = 500;

    private const DEFAULT_LANGUAGE = 0;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly RecordAccessService $recordAccessService,
    ) {
    }

    public function getName(): string
    {
        return 'get_page_tree';
    }

    public function getDescription(): string
    {
        return 'Returns the TYPO3 page tree as a nested structure. Start with pageUid 0 for the root of the'
            . ' installation. Only pages the backend user may see are returned. A tree holds at most '
            . self::MAX_PAGES . ' pages - when "truncated" is true, ask for a subpage or a smaller depth.';
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
        $languageId = $this->getIntArgument($arguments, 'languageId');
        // The page tree of the backend shows the pages of the default language to everybody
        if ($languageId !== self::DEFAULT_LANGUAGE) {
            $this->recordAccessService->assertLanguageReadable($languageId);
        }

        $pageTree = $this->pageRepository->findTree(
            $this->getIntArgument($arguments, 'pageUid'),
            $depth,
            $languageId,
            self::MAX_PAGES
        );

        return [
            'pageUid' => $this->getIntArgument($arguments, 'pageUid'),
            'depth' => $depth,
            'tree' => $pageTree['tree'],
            'truncated' => $pageTree['truncated'],
        ];
    }
}
