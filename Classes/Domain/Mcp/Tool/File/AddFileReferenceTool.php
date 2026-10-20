<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\FileRepository;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Connects a file that already exists in this installation to a file field of a record, which is what an editor
 * does when adding an image to a content element or to a page.
 */
class AddFileReferenceTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly FileRepository $fileRepository,
        private readonly RecordRepository $recordRepository,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function getName(): string
    {
        return 'add_file_reference';
    }

    public function getDescription(): string
    {
        return 'Adds an existing file to a file field of a record, for example the image of a content element'
            . ' or the social media image of a page. Find the file uid with "search_files" first. Existing'
            . ' files of the field are kept, the new one is appended. Pass "fields" to describe the file in this'
            . ' place right away, for example its title, alternative text, description (caption) or link - they'
            . ' are written together with the reference, no "update_record" is needed afterwards.';
    }

    public function getParameters(): array
    {
        return [
            'fileUid' => [
                'type' => 'integer',
                'description' => 'Uid of the file, as returned by "search_files"',
                'required' => true,
            ],
            'table' => [
                'type' => 'string',
                'description' => 'Table of the record the file is added to, for example "tt_content" or "pages"',
                'required' => true,
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Uid of the record the file is added to',
                'required' => true,
            ],
            'fieldName' => [
                'type' => 'string',
                'description' => 'Name of the file field, for example "image", "assets" or "media". Call'
                    . ' "get_schema" with the table to see which fields are of type "file".',
                'required' => true,
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Optional fields of the new file reference, for example {"title": "...",'
                    . ' "alternative": "...", "description": "...", "link": "t3://page?uid=1"}. An empty value'
                    . ' falls back to the metadata of the file. Call "get_schema" with "sys_file_reference" to see'
                    . ' all fields. Unknown fields are reported instead of ignored; the fields that connect the'
                    . ' reference to the file and the record (uid_local, uid_foreign, tablenames, fieldname, pid,'
                    . ' ...) can not be set.',
                'default' => [],
            ],
        ];
    }

    /**
     * @throws DataHandlerException
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $fileUid = $this->getIntArgument($arguments, 'fileUid');
        $table = $this->getStringArgument($arguments, 'table');
        $uid = $this->getIntArgument($arguments, 'uid');
        $fieldName = $this->getStringArgument($arguments, 'fieldName');
        $fields = $this->getArrayArgument($arguments, 'fields');

        // The DataHandler refuses a file outside the file mounts as well, but a lookup that already respects
        // them keeps the answer from confirming that a file with this uid exists somewhere else.
        $file = $this->fileRepository->findFileByUid(
            $fileUid,
            $this->backendUserService->hasFullFileAccess() ? null : $this->backendUserService->getFileMounts()
        );
        if ($file === null) {
            throw new ToolExecutionException(
                'There is no file with uid ' . $fileUid . ' this backend user may use. Use "search_files" to'
                . ' find a file.',
                1756801032
            );
        }

        $record = $this->recordRepository->findByUid($table, $uid);
        if ($record === null) {
            throw new ToolExecutionException(
                'There is no record with uid ' . $uid . ' in "' . $table . '"',
                1756801036
            );
        }

        $referenceUid = $this->dataHandlerService->addFileReference(
            $fileUid,
            $table,
            $uid,
            $fieldName,
            (int)($record['pid'] ?? 0),
            $fields
        );

        $result = [
            'added' => true,
            'fileReferenceUid' => $referenceUid,
            'file' => $file,
            'table' => $table,
            'uid' => $uid,
            'fieldName' => $fieldName,
        ];
        if ($fields !== []) {
            $result['fields'] = $fields;
        }

        return $result;
    }
}
