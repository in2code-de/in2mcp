<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\RecentChangeService;
use In2code\In2mcp\Domain\Service\TableAccessService;

class GetRecentlyChangedPagesTool extends AbstractTool
{
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly RecentChangeService $recentChangeService,
        private readonly TableAccessService $tableAccessService,
    ) {
    }

    public function getName(): string
    {
        return 'get_recently_changed_pages';
    }

    public function getDescription(): string
    {
        return 'Returns the pages that were changed most recently, newest first. A page counts as changed when its'
            . ' properties, one of its translations or one of its content elements changed - "lastChanged" is the'
            . ' newest of these, "pagePropertiesChanged" and "contentChanged" tell them apart. Times are ISO 8601.'
            . ' For administrators "changedBy" names the backend user of that change.'
            . ' Pass "pageUid" to look only at one site or section.';
    }

    public function getParameters(): array
    {
        return [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Only this page and the pages below it. Leave it out for every page of the user.',
                'default' => 0,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of pages returned, maximum ' . self::MAX_LIMIT,
                'default' => 10,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->tableAccessService->assertReadable(PageRepository::TABLE_NAME);
        return $this->recentChangeService->getRecentlyChangedPages(
            $this->getIntArgument($arguments, 'pageUid'),
            max(1, min($this->getIntArgument($arguments, 'limit'), self::MAX_LIMIT))
        );
    }
}
