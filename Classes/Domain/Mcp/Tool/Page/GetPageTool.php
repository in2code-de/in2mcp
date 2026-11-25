<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\RecordAccessService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\ToolExecutionException;

class GetPageTool extends AbstractTool
{
    protected const READ_ONLY = true;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly ContentRepository $contentRepository,
        private readonly TableAccessService $tableAccessService,
        private readonly RecordAccessService $recordAccessService,
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
            $this->throwPageNotFound($pageUid);
        }

        $result = ['page' => $page];
        if ((bool)$this->getArgument($arguments, 'withContent')) {
            $languageId = $this->getIntArgument($arguments, 'languageId');
            $this->tableAccessService->assertReadable(ContentRepository::TABLE_NAME);
            $this->recordAccessService->assertLanguageReadable($languageId);
            $result['content'] = $this->getContentByColumn($pageUid, $languageId);
        }

        return $result;
    }

    /**
     * @throws ToolExecutionException
     */
    private function throwPageNotFound(int $pageUid): never
    {
        if ($this->pageRepository->isDeletedPageReadable($pageUid)) {
            throw new ToolExecutionException(
                'Page ' . $pageUid . ' is deleted. It can be restored in the recycler of the backend, and a'
                . ' translation that still points to it with "l10n_parent" is left over from it.',
                1764079500
            );
        }
        throw new ToolExecutionException(
            'Page ' . $pageUid . ' does not exist or the backend user is not allowed to see it',
            1756800700
        );
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
