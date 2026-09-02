<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Cache;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\BackendUserService;
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

    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly BackendUserService $backendUserService,
    ) {
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

        $this->assertAllowed($scope);
        $this->dataHandlerService->clearCache($scope);

        return ['cleared' => true, 'scope' => $scope];
    }

    /**
     * The DataHandler silently does nothing when the user may not flush, and it does not check a single page at
     * all - any uid clears any page. Both is answered here instead, so a refusal is a refusal and a page stays
     * within the reach of its editor.
     *
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function assertAllowed(string $scope): void
    {
        if (ctype_digit($scope)) {
            if ($this->backendUserService->isInWebMount((int)$scope) === false) {
                throw new ToolExecutionException(
                    'Page ' . $scope . ' is outside the page mounts of this backend user',
                    1756801812
                );
            }
            return;
        }

        if ($scope === self::SCOPE_PAGES && $this->backendUserService->mayClearPageCache() === false) {
            throw new ToolExecutionException(
                'This backend user may not flush the page cache. Clearing the cache of a single page by its'
                . ' uid works without that permission.',
                1756801816
            );
        }

        if ($scope === self::SCOPE_ALL && $this->backendUserService->mayClearAllCaches() === false) {
            throw new ToolExecutionException(
                'This backend user may not flush all caches',
                1756801820
            );
        }
    }
}
