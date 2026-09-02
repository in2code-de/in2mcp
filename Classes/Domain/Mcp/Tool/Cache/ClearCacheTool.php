<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Cache;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Without this, a change made through MCP is in the database but not yet visible in the frontend, and the only
 * way out is a shell.
 */
class ClearCacheTool extends AbstractTool
{
    private const SCOPE_PAGES = 'pages';
    private const SCOPE_ALL = 'all';

    public function __construct(private readonly DataHandlerService $dataHandlerService)
    {
    }

    public function getName(): string
    {
        return 'clear_cache';
    }

    public function getDescription(): string
    {
        return 'Clears the TYPO3 cache so that changes become visible in the frontend. Use "pages" after'
            . ' editing content, a page uid to clear only that page, and "all" only when something outside the'
            . ' content changed. Whether the backend user may do this is decided by TYPO3, as in the backend.';
    }

    public function getParameters(): array
    {
        return [
            'scope' => [
                'type' => 'string',
                'description' => 'What to clear: "pages" for the page cache, "all" for every cache, or the uid'
                    . ' of a single page as a number',
                'default' => self::SCOPE_PAGES,
            ],
        ];
    }

    /**
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $scope = trim($this->getStringArgument($arguments, 'scope'));

        if (in_array($scope, [self::SCOPE_PAGES, self::SCOPE_ALL], true) === false
            && ctype_digit($scope) === false) {
            throw new ToolExecutionException(
                'The scope has to be "pages", "all" or the uid of a page, "' . $scope . '" is none of those',
                1756801800
            );
        }

        $this->dataHandlerService->clearCache($scope);

        return ['cleared' => true, 'scope' => $scope];
    }
}
