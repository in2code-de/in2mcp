<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Every write of this extension goes through the DataHandler, so all permission checks, hooks, references,
 * slug generation, versioning and the history of TYPO3 apply exactly as if an editor had done it in the backend.
 * A denied write therefore means that the authenticated backend user is not allowed to perform it.
 */
class DataHandlerService
{
    public const NEW_RECORD_PLACEHOLDER = 'NEW_in2mcp';

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly TcaService $tcaService,
    ) {
    }

    /**
     * @return array<string, int> Map of the new record placeholders to the created uids
     * @throws DataHandlerException
     * @throws UserNotFoundException
     */
    public function process(array $dataMap, array $commandMap = []): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, $commandMap, $this->backendUserService->getBackendUser());

        if ($dataMap !== []) {
            $dataHandler->process_datamap();
        }
        if ($commandMap !== []) {
            $dataHandler->process_cmdmap();
        }

        if ($dataHandler->errorLog !== []) {
            throw new DataHandlerException(
                'TYPO3 refused the operation: ' . implode(' | ', $dataHandler->errorLog),
                1756800800
            );
        }

        return array_map('intval', $dataHandler->substNEWwithIDs);
    }

    /**
     * Creates a single record and returns its uid
     *
     * @throws DataHandlerException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function createRecord(string $table, int $pid, array $fields): int
    {
        $this->assertWriteAllowed($table);
        $this->assertKnownFields($table, $fields);

        $fields['pid'] = $pid;
        $newIds = $this->process([$table => [self::NEW_RECORD_PLACEHOLDER => $fields]]);
        $uid = $newIds[self::NEW_RECORD_PLACEHOLDER] ?? 0;

        if ($uid === 0) {
            throw new DataHandlerException(
                'The record in "' . $table . '" was not created and TYPO3 reported no reason',
                1756800804
            );
        }

        return $uid;
    }

    /**
     * @throws DataHandlerException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function updateRecord(string $table, int $uid, array $fields): void
    {
        $this->assertWriteAllowed($table);
        $this->assertKnownFields($table, $fields);

        if ($fields === []) {
            throw new ToolExecutionException('No fields to update were given', 1756800808);
        }

        $this->process([$table => [$uid => $fields]]);
    }

    /**
     * @throws DataHandlerException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function deleteRecord(string $table, int $uid): void
    {
        $this->assertWriteAllowed($table);
        $this->process([], [$table => [$uid => ['delete' => 1]]]);
    }

    /**
     * Moves a record. A negative target uid means "behind the record with this uid", a positive one means
     * "into the page with this uid".
     *
     * @throws DataHandlerException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function moveRecord(string $table, int $uid, int $target): void
    {
        $this->assertWriteAllowed($table);
        $this->process([], [$table => [$uid => ['move' => $target]]]);
    }

    /**
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function assertWriteAllowed(string $table): void
    {
        if ($this->backendUserService->isTableModifyAllowed($table) === false) {
            throw new ToolExecutionException(
                'The backend user is not allowed to write records of type "' . $table . '"',
                1756800812
            );
        }
    }

    /**
     * Unknown field names are reported instead of being dropped silently, so a client learns that it guessed
     * a field that does not exist in this installation.
     *
     * @throws ToolExecutionException
     */
    private function assertKnownFields(string $table, array $fields): void
    {
        $unknownFields = [];
        foreach (array_keys($fields) as $fieldName) {
            if ($this->tcaService->isFieldOfTable($table, (string)$fieldName) === false) {
                $unknownFields[] = (string)$fieldName;
            }
        }

        if ($unknownFields !== []) {
            throw new ToolExecutionException(
                'Unknown fields for "' . $table . '": ' . implode(', ', $unknownFields)
                . '. Call "get_schema" to see the fields of this installation.',
                1756800816
            );
        }
    }
}
