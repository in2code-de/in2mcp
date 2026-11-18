<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Workspace;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\WorkspaceService;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class SwitchWorkspaceTool extends AbstractTool
{
    public function __construct(private readonly WorkspaceService $workspaceService)
    {
    }

    public function getName(): string
    {
        return 'switch_workspace';
    }

    public function getDescription(): string
    {
        return 'Changes the workspace all following reads and changes happen in, exactly like the workspace'
            . ' selector of the TYPO3 backend: the choice is stored at the backend user, so the backend works in'
            . ' this workspace as well after its next reload. Uid 0 is live, where every change is visible at'
            . ' once. Call "get_workspaces" for the uids.';
    }

    public function getParameters(): array
    {
        return [
            'workspaceId' => [
                'type' => 'integer',
                'description' => 'Uid of the workspace as returned by "get_workspaces", 0 for live',
                'required' => true,
            ],
        ];
    }

    /**
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $this->workspaceService->switchWorkspace($this->getIntArgument($arguments, 'workspaceId'));

        return [
            'switched' => true,
            'currentWorkspace' => $this->workspaceService->getWorkspaceId(),
        ];
    }
}
