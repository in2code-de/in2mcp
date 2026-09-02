<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\FileRepository;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
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
    /**
     * Placeholder of a record that is created in the current DataHandler run. It must not contain an underscore:
     * relation fields are lists of "table_uid" pairs, so a placeholder with an underscore is torn apart when it
     * is used inside such a list, and the new record silently never gets connected.
     */
    public const NEW_RECORD_PLACEHOLDER = 'NEWin2mcp';

    private const FILE_REFERENCE_TABLE = 'sys_file_reference';

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly TableAccessService $tableAccessService,
        private readonly TcaService $tcaService,
        private readonly InlineRelationService $inlineRelationService,
        private readonly FileRepository $fileRepository,
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
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function createRecord(string $table, int $pid, array $fields): int
    {
        $this->tableAccessService->assertWritable($table);
        $this->assertKnownFields($table, $fields);
        $this->assertWritableValues($table, $fields, 0);

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
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function updateRecord(string $table, int $uid, array $fields): void
    {
        $this->tableAccessService->assertWritable($table);
        $this->assertKnownFields($table, $fields);
        $this->assertWritableValues($table, $fields, $uid);

        if ($fields === []) {
            throw new ToolExecutionException('No fields to update were given', 1756800808);
        }

        $this->process([$table => [$uid => $fields]]);
    }

    /**
     * @throws DataHandlerException
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     */
    public function deleteRecord(string $table, int $uid): void
    {
        $this->tableAccessService->assertWritable($table);
        $this->process([], [$table => [$uid => ['delete' => 1]]]);
    }

    /**
     * Moves a record. A negative target uid means "behind the record with this uid", a positive one means
     * "into the page with this uid".
     *
     * Fields given as $update are written by the move itself. This is how the backend moves a content element
     * into another column or into a container: the target only decides the position in the sorting, the column
     * and the container are ordinary fields that have to travel with the move.
     *
     * @param array<string, mixed> $update
     * @throws DataHandlerException
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     * @throws ToolExecutionException
     */
    public function moveRecord(string $table, int $uid, int $target, array $update = []): void
    {
        $this->tableAccessService->assertWritable($table);

        if ($update === []) {
            $this->process([], [$table => [$uid => ['move' => $target]]]);
            return;
        }

        $this->assertKnownFields($table, $update);
        $this->process([], [$table => [$uid => ['move' => [
            'action' => 'paste',
            'target' => $target,
            'update' => $update,
        ]]]]);
    }

    /**
     * Creates a record as the child of an inline field of another record, which is what an editor does with the
     * "Create new" button inside such a field.
     *
     * The child and the list in the field of the parent are written in one DataHandler run. That is what keeps
     * the child counter of the parent correct - a child created on its own leaves the parent at zero, and a
     * parent whose counter says zero has no children as far as Extbase is concerned, however many records point
     * at it.
     *
     * @return int Uid of the created child
     * @throws DataHandlerException
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function createChildRecord(
        string $parentTable,
        int $parentUid,
        string $parentField,
        int $pid,
        array $fields
    ): int {
        $relation = $this->tcaService->getInlineRelation($parentTable, $parentField);
        if ($relation === null) {
            throw new ToolExecutionException(
                'The field "' . $parentField . '" of "' . $parentTable . '" is no inline relation, so it holds'
                . ' no child records. Call "get_schema" with the table to see its fields.',
                1756801512
            );
        }

        $this->tableAccessService->assertWritable($parentTable);
        $this->tableAccessService->assertWritable($relation['foreignTable']);
        $this->assertKnownFields($relation['foreignTable'], $fields);

        $children = $this->inlineRelationService->findChildUids($relation, $parentUid);
        $children[] = self::NEW_RECORD_PLACEHOLDER;

        $fields['pid'] = $pid;
        $newIds = $this->process([
            $parentTable => [
                $parentUid => [$parentField => implode(',', $children)],
            ],
            $relation['foreignTable'] => [
                self::NEW_RECORD_PLACEHOLDER => $fields,
            ],
        ]);

        $uid = $newIds[self::NEW_RECORD_PLACEHOLDER] ?? 0;
        if ($uid === 0) {
            throw new DataHandlerException(
                'The child record in "' . $relation['foreignTable'] . '" was not created and TYPO3 reported no'
                . ' reason',
                1756801516
            );
        }

        return $uid;
    }

    /**
     * Attaches a file to a file field of a record by creating a sys_file_reference.
     *
     * The reference is created and connected in one DataHandler run, because the field of the parent record
     * holds the list of its references and has to be written together with the new reference. Existing
     * references are kept, so a second file is appended instead of replacing the first one.
     *
     * @return int Uid of the created file reference
     * @throws DataHandlerException
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function addFileReference(int $fileUid, string $table, int $uid, string $fieldName, int $pid): int
    {
        $this->tableAccessService->assertWritable($table);
        $this->assertKnownFields($table, [$fieldName => '']);

        $references = $this->fileRepository->findReferenceUids($table, $uid, $fieldName);
        $references[] = self::NEW_RECORD_PLACEHOLDER;

        // Only the file itself is described here. Which record the reference belongs to, and in which order, is
        // derived by the DataHandler from the list in the field of the parent record. Setting tablenames,
        // uid_foreign or fieldname here as well makes the reference count of the parent run out of sync.
        $newIds = $this->process([
            $table => [
                $uid => [$fieldName => implode(',', $references)],
            ],
            self::FILE_REFERENCE_TABLE => [
                self::NEW_RECORD_PLACEHOLDER => [
                    'table_local' => 'sys_file',
                    'uid_local' => $fileUid,
                    'pid' => $pid,
                ],
            ],
        ]);

        $referenceUid = $newIds[self::NEW_RECORD_PLACEHOLDER] ?? 0;
        if ($referenceUid === 0) {
            throw new DataHandlerException(
                'The file reference was not created and TYPO3 reported no reason',
                1756801016
            );
        }

        return $referenceUid;
    }

    /**
     * Creates the translation of a record in a language, which is the "Translate" button of the backend.
     *
     * @return int Uid of the translated record
     * @throws DataHandlerException
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function localizeRecord(string $table, int $uid, int $languageId): int
    {
        $this->tableAccessService->assertWritable($table);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [],
            [$table => [$uid => ['localize' => $languageId]]],
            $this->backendUserService->getBackendUser()
        );
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            throw new DataHandlerException(
                'TYPO3 refused to translate the record: ' . implode(' | ', $dataHandler->errorLog),
                1756801600
            );
        }

        $translatedUid = (int)($dataHandler->copyMappingArray[$table][$uid] ?? 0);
        if ($translatedUid === 0) {
            throw new DataHandlerException(
                'The record was not translated. It may already have a translation in this language, or the'
                . ' table has no language configuration.',
                1756801604
            );
        }

        return $translatedUid;
    }

    /**
     * An inline field carries the number of its children in the database and the list of their uids in the
     * DataHandler. Writing the number attaches foreign records, so the value is checked before it is handed on.
     *
     * @throws Exception
     * @throws ToolExecutionException
     */
    private function assertWritableValues(string $table, array $fields, int $uid): void
    {
        foreach ($fields as $fieldName => $value) {
            $this->inlineRelationService->assertWritableValue($table, (string)$fieldName, $value, $uid);
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
            $fieldName = (string)$fieldName;
            if ($this->tcaService->isSortingField($table, $fieldName)) {
                throw new ToolExecutionException(
                    'The sorting of a record is not written directly, it is the result of its position. Use'
                    . ' "move_record" to put the record where it belongs.',
                    1756801508
                );
            }
            if ($this->tcaService->isFieldOfTable($table, $fieldName) === false) {
                $unknownFields[] = $fieldName;
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
