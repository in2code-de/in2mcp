<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Exception\ToolExecutionException;

class GetPageTool extends AbstractTool
{
    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function getName(): string
    {
        return 'get_page';
    }

    public function getDescription(): string
    {
        return 'Returns a single TYPO3 page with all its fields and its content elements grouped by column'
            . ' position. Use this to study how comparable pages of this installation are built before'
            . ' creating a new one.';
    }

    public function getParameters(): array
    {
        return [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Uid of the page',
                'required' => true,
            ],
            'languageId' => [
                'type' => 'integer',
                'description' => 'Language of the content elements, 0 is the default language',
                'default' => 0,
            ],
            'withContent' => [
                'type' => 'boolean',
                'description' => 'Whether the content elements of the page are included',
                'default' => true,
            ],
        ];
    }

    /**
     * @throws ToolExecutionException
     */
    public function execute(array $arguments): array
    {
        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        $page = $this->pageRepository->findByUid($pageUid);

        if ($page === null) {
            throw new ToolExecutionException(
                'Page ' . $pageUid . ' does not exist or the backend user is not allowed to see it',
                1756800700
            );
        }

        $result = ['page' => $page];
        if ((bool)$this->getArgument($arguments, 'withContent')) {
            $result['content'] = $this->getContentByColumn(
                $pageUid,
                $this->getIntArgument($arguments, 'languageId')
            );
        }

        return $result;
    }

    private function getContentByColumn(int $pageUid, int $languageId): array
    {
        $contentByColumn = [];
        foreach ($this->contentRepository->findByPage($pageUid, $languageId) as $contentElement) {
            $contentByColumn['colPos_' . (int)($contentElement['colPos'] ?? 0)][] = $contentElement;
        }
        return $contentByColumn;
    }
}
