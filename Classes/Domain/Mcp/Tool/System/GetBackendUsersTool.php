<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\System;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\BackendUserActivityService;

class GetBackendUsersTool extends AbstractTool
{
    public function __construct(private readonly BackendUserActivityService $backendUserActivityService)
    {
    }

    public function getName(): string
    {
        return 'get_backend_users';
    }

    public function getDescription(): string
    {
        return 'Administrators only. Lists the backend users with their last login, their active multi factor'
            . ' authentication providers (an empty list means MFA is not set up) and the time of their last change'
            . ' in the record history, last login first. Every MCP request logs its backend user in as well, so a'
            . ' user connected through MCP shows the time of its last request. Times are ISO 8601.';
    }

    public function execute(array $arguments): array
    {
        return $this->backendUserActivityService->getBackendUsers();
    }
}
