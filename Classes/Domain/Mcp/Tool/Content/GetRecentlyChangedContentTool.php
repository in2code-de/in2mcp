<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Content;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Service\RecentChangeService;
use In2code\In2mcp\Domain\Service\TableAccessService;

class GetRecentlyChangedContentTool extends AbstractTool
{
    private const MAX_LIMIT = 100;
    private const ALL_LANGUAGES = -1;

    public function __construct(
        private readonly RecentChangeService $recentChangeService,
        private readonly TableAccessService $tableAccessService,
    ) {
    }

    public function getName(): string
    {
        return 'get_recently_changed_content';
    }

    public function getDescription(): string
    {
        return 'Returns the content elements that were changed most recently, newest first, together with the'
            . ' title and slug of their page. Hidden elements are included and marked. Times are ISO 8601.'
            . ' For administrators "changedBy" names the backend user of the change.'
            . ' Pass "pageUid" to look only at one site or section.';
    }

    public function getParameters(): array
    {
        return [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Only content on this page and the pages below it. Leave it out for every page'
                    . ' of the user.',
                'default' => 0,
            ],
            'languageId' => [
                'type' => 'integer',
                'description' => 'Language of the content elements, 0 is the default language. Leave it out to get'
                    . ' every language.',
                'default' => self::ALL_LANGUAGES,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of content elements returned, maximum ' . self::MAX_LIMIT,
                'default' => 10,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->tableAccessService->assertReadable(ContentRepository::TABLE_NAME);
        $languageId = $this->getIntArgument($arguments, 'languageId');

        return $this->recentChangeService->getRecentlyChangedContent(
            $this->getIntArgument($arguments, 'pageUid'),
            $languageId === self::ALL_LANGUAGES ? null : $languageId,
            max(1, min($this->getIntArgument($arguments, 'limit'), self::MAX_LIMIT))
        );
    }
}
