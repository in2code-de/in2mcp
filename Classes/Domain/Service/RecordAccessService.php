<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Decides whether a backend user may see a single record: it has to lie on a page inside the page mounts, and it
 * has to be in a language the user may access. The backend shows only those languages in the page and the list
 * module - only the page tree shows the pages of the default language to everybody.
 */
readonly class RecordAccessService
{
    private const DEFAULT_LANGUAGE = 0;

    public function __construct(
        private BackendUserService $backendUserService,
        private TcaService $tcaService,
    ) {
    }

    /**
     * @param array<string, mixed> $record
     * @throws UserNotFoundException
     */
    public function isRecordReadable(string $table, array $record): bool
    {
        return $this->backendUserService->isRecordReachable($table, $record)
            && $this->isLanguageOfRecordReadable($table, $record);
    }

    /**
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function assertLanguageReadable(int $languageId): void
    {
        if ($this->backendUserService->isLanguageAllowed($languageId) === false) {
            throw new ToolExecutionException(
                'The backend user may not access the language ' . $languageId . '. Call "get_backend_user" to'
                . ' see the languages it may use.',
                1756802600
            );
        }
    }

    /**
     * Administrators and users without a list of languages may read every language, so the schema is only asked
     * for an editor whose languages are restricted
     *
     * @param array<string, mixed> $record
     * @throws UserNotFoundException
     */
    private function isLanguageOfRecordReadable(string $table, array $record): bool
    {
        $isUnrestricted = $this->backendUserService->getAllowedLanguageIds() === null;
        $languageField = $isUnrestricted ? null : $this->tcaService->getLanguageField($table);
        $languageId = $languageField === null ? null : (int)($record[$languageField] ?? self::DEFAULT_LANGUAGE);
        $isPageOfTheTree = $table === PageRepository::TABLE_NAME && $languageId === self::DEFAULT_LANGUAGE;

        return $languageId === null
            || $isPageOfTheTree
            || $this->backendUserService->isLanguageAllowed($languageId);
    }
}
