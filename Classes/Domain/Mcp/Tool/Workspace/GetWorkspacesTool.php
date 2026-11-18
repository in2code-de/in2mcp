<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Workspace;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\WorkspaceService;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class GetWorkspacesTool extends AbstractTool
{
    public function __construct(private readonly WorkspaceService $workspaceService)
    {
    }

    public function getName(): string
    {
        return 'get_workspaces';
    }

    public function getDescription(): string
    {
        return 'Lists live (uid 0) and every workspace the backend user may work in, and marks the current one.'
            . ' Every read and every change happens in the current workspace - in a workspace a change is a draft'
            . ' that is published later, in live it is visible at once. Use "switch_workspace" to change it.';
    }

    /**
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        return [
            'currentWorkspace' => $this->workspaceService->getWorkspaceId(),
            'workspaces' => $this->workspaceService->findAccessibleWorkspaces(),
        ];
    }
}
