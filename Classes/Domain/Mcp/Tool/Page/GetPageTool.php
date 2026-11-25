<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Model\TranslationMode;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\FieldSelectionService;
use In2code\In2mcp\Domain\Service\RecordAccessService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\ToolExecutionException;

class GetPageTool extends AbstractTool
{
    protected const READ_ONLY = true;

    public function __construct(
        private readonly PageRepository $pageRepository,
        private readonly ContentRepository $contentRepository,
        private readonly TableAccessService $tableAccessService,
        private readonly RecordAccessService $recordAccessService,
        private readonly TcaService $tcaService,
        private readonly FieldSelectionService $fieldSelectionService,
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
            . ' creating a new one. With a "languageId" above 0 it adds the page translation of that language'
            . ' ("translation", null when there is none) and how its content is translated ("translationMode":'
            . ' "connected" to the default language, "free", "mixed" or "empty").';
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
                'description' => 'Language of the content elements and of the page translation, 0 is the'
                    . ' default language',
                'default' => 0,
            ],
            'withContent' => [
                'type' => 'boolean',
                'description' => 'Whether the content elements of the page are included',
                'default' => true,
            ],
            'contentFields' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Fields of the content elements to return, for example ["CType", "header"]. uid'
                    . ' and pid are always returned. Leave it out to get every field.',
                'default' => [],
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

        $languageId = $this->getIntArgument($arguments, 'languageId');
        $this->recordAccessService->assertLanguageReadable($languageId);
        $isTranslation = $languageId > 0;

        $result = ['page' => $page];
        if ($isTranslation) {
            $result['translation'] = $this->pageRepository->findTranslation($pageUid, $languageId);
        }
        if ((bool)$this->getArgument($arguments, 'withContent')) {
            $this->tableAccessService->assertReadable(ContentRepository::TABLE_NAME);
            $contentFields = $this->fieldSelectionService->getSelectedFields(
                ContentRepository::TABLE_NAME,
                $this->getArrayArgument($arguments, 'contentFields')
            );
            $contentElements = $this->contentRepository->findByPage($pageUid, $languageId);
            $result['content'] = $this->groupByColumn($contentElements, $contentFields);
            if ($isTranslation) {
                $result['translationMode'] = $this->getTranslationMode($contentElements)->value;
            }
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $contentElements
     */
    private function getTranslationMode(array $contentElements): TranslationMode
    {
        $parentField = (string)$this->tcaService->getTranslationParentField(ContentRepository::TABLE_NAME);
        return TranslationMode::fromTranslationParents(
            array_map(static fn (array $element): int => (int)($element[$parentField] ?? 0), $contentElements)
        );
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

    /**
     * @param list<array<string, mixed>> $contentElements
     * @param list<string> $contentFields
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByColumn(array $contentElements, array $contentFields): array
    {
        $contentByColumn = [];
        foreach ($contentElements as $contentElement) {
            $contentByColumn['colPos_' . (int)($contentElement['colPos'] ?? 0)][]
                = $this->fieldSelectionService->selectFields($contentElement, $contentFields);
        }
        return $contentByColumn;
    }
}
