<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Domain\Service\FieldSelectionService;
use In2code\In2mcp\Domain\Service\LinkTargetService;

class UpdatePageTool extends AbstractTool
{
    public function __construct(
        private readonly LinkTargetService $linkTargetService,
        private readonly DataHandlerService $dataHandlerService,
        private readonly PageRepository $pageRepository,
        private readonly FieldSelectionService $fieldSelectionService,
    ) {
    }

    public function getName(): string
    {
        return 'update_page';
    }

    public function getDescription(): string
    {
        return 'Changes fields of an existing page. Only the given fields are written, everything else stays'
            . ' untouched.'
            . self::LINK_REPORT_DESCRIPTION;
    }

    public function getParameters(): array
    {
        return [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Uid of the page to change',
                'required' => true,
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Fields to write, for example title, nav_title, slug, description or hidden',
                'required' => true,
            ],
            'returnFields' => $this->getReturnFieldsParameter('["title", "slug"]'),
        ];
    }

    public function execute(array $arguments): array
    {
        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        $returnFields = $this->fieldSelectionService->getSelectedFields(
            PageRepository::TABLE_NAME,
            $this->getArrayArgument($arguments, 'returnFields')
        );
        $this->dataHandlerService->updateRecord(
            PageRepository::TABLE_NAME,
            $pageUid,
            $this->getArrayArgument($arguments, 'fields')
        );

        return [
            'updated' => true,
            'pageUid' => $pageUid,
            'page' => $this->fieldSelectionService->selectFields(
                $this->pageRepository->findByUid($pageUid),
                $returnFields
            ),
        ] + $this->linkTargetService->getLinkReport(
            PageRepository::TABLE_NAME,
            $this->getArrayArgument($arguments, 'fields')
        );
    }
}
