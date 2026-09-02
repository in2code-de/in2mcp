<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;

class UpdatePageTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly PageRepository $pageRepository,
    ) {
    }

    public function getName(): string
    {
        return 'update_page';
    }

    public function getDescription(): string
    {
        return 'Changes fields of an existing page. Only the given fields are written, everything else stays'
            . ' untouched.';
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
        ];
    }

    public function execute(array $arguments): array
    {
        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        $this->dataHandlerService->updateRecord(
            PageRepository::TABLE_NAME,
            $pageUid,
            $this->getArrayArgument($arguments, 'fields')
        );

        return [
            'updated' => true,
            'pageUid' => $pageUid,
            'page' => $this->pageRepository->findByUid($pageUid),
        ];
    }
}
