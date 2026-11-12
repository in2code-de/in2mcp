<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\System;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\BackendUserActivityService;

class GetRecentChangesTool extends AbstractTool
{
    private const MAX_LIMIT = 100;

    public function __construct(private readonly BackendUserActivityService $backendUserActivityService)
    {
    }

    public function getName(): string
    {
        return 'get_recent_changes';
    }

    public function getDescription(): string
    {
        return 'Administrators only. Returns the newest entries of the record history of the backend: who'
            . ' created, changed, moved or deleted which record when, with the names of the changed fields but'
            . ' never their values. Times are ISO 8601.';
    }

    public function getParameters(): array
    {
        return [
            'userUid' => [
                'type' => 'integer',
                'description' => 'Only changes of this backend user, see "get_backend_users"',
                'default' => 0,
            ],
            'table' => [
                'type' => 'string',
                'description' => 'Only changes of records of this table, for example "pages" or "tt_content"',
                'default' => '',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of changes returned, maximum ' . self::MAX_LIMIT,
                'default' => 20,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        return $this->backendUserActivityService->getRecentChanges(
            $this->getIntArgument($arguments, 'userUid'),
            $this->getStringArgument($arguments, 'table'),
            max(1, min($this->getIntArgument($arguments, 'limit'), self::MAX_LIMIT))
        );
    }
}
