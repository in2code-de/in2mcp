<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Redirects\Service\RedirectService;

/**
 * EXT:redirects is optional, so its service arrives as an optional reference of Services.yaml
 */
class RedirectMatchService
{
    private const REDIRECT_TABLE = 'sys_redirect';

    public function __construct(
        private readonly TableAccessService $tableAccessService,
        private readonly ?RedirectService $redirectService = null,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->redirectService !== null;
    }

    /**
     * @return array{target: string, statusCode: int, redirectUid?: int}|null
     * @throws UserNotFoundException
     */
    public function findRedirect(string $host, string $path, string $query): ?array
    {
        $redirect = $this->redirectService?->matchRedirect($host, $path, $query);
        $description = null;
        if (is_array($redirect) && $redirect !== []) {
            $description = [
                'target' => (string)($redirect['target'] ?? ''),
                'statusCode' => (int)($redirect['target_statuscode'] ?? 0),
            ];
            if ($this->tableAccessService->isReadable(self::REDIRECT_TABLE)) {
                $description['redirectUid'] = (int)($redirect['uid'] ?? 0);
            }
        }
        return $description;
    }
}
