<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\FileRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Throwable;
use TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SysLog\Action\File as SystemLogFileAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Deletes a file through the file abstraction layer, so the storage checks the permissions of the backend user
 * and a recycler folder is respected. A file that is still used is refused, as in the filelist module.
 */
class DeleteFileTool extends AbstractTool
{
    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly ResourceFactory $resourceFactory,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function getName(): string
    {
        return 'delete_file';
    }

    public function getDescription(): string
    {
        return 'Deletes a file from the file storage. A file that is still used by a record is not deleted - the'
            . ' answer lists those records, and "delete_record" on the "sys_file_reference" removes a usage first.'
            . ' If the storage has a recycler folder, the file is moved there instead of being deleted.';
    }

    public function getParameters(): array
    {
        return [
            'fileUid' => [
                'type' => 'integer',
                'description' => 'Uid of the file, as returned by "search_files"',
                'required' => true,
            ],
        ];
    }

    /**
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $file = $this->getFile($this->getIntArgument($arguments, 'fileUid'));
        $this->assertFileIsUnused($file);

        $identifier = $file->getCombinedIdentifier();
        $this->deleteFile($file);
        $movedToRecycler = $file->isDeleted() === false;

        $this->backendUserService->logFileAction(
            SystemLogFileAction::DELETE,
            SystemLogErrorClassification::MESSAGE,
            'MCP: Deleted "{identifier}"',
            ['identifier' => $identifier, 'movedToRecycler' => $movedToRecycler]
        );

        return [
            'deleted' => true,
            'movedToRecycler' => $movedToRecycler,
            'file' => [
                'uid' => $file->getUid(),
                'name' => $file->getName(),
                'identifier' => $identifier,
            ],
        ];
    }

    /**
     * A file outside the file mounts is reported as not found, so the answer does not tell that it exists.
     *
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function getFile(int $fileUid): File
    {
        $fileMounts = $this->backendUserService->hasFullFileAccess()
            ? null
            : $this->backendUserService->getFileMounts();
        $file = null;
        if ($this->fileRepository->findFileByUid($fileUid, $fileMounts) !== null) {
            try {
                $file = $this->resourceFactory->getFileObject($fileUid);
            } catch (Throwable) {
                $file = null;
            }
        }

        if (($file instanceof File) === false || $file->isDeleted()) {
            throw new ToolExecutionException(
                'There is no file with uid ' . $fileUid . ' that this backend user can access.'
                . ' Call "search_files" to find the uid of a file.',
                1756802200
            );
        }
        return $file;
    }

    /**
     * @throws Exception
     * @throws ToolExecutionException
     */
    private function assertFileIsUnused(File $file): void
    {
        $usages = $this->fileRepository->findUsages($file->getUid());
        if ($usages !== []) {
            $records = array_map(
                static fn (array $usage): string => $usage['table'] . ':' . $usage['uid']
                    . ($usage['field'] !== '' ? ' field ' . $usage['field'] : '')
                    . ($usage['reference'] > 0 ? ' via sys_file_reference:' . $usage['reference'] : ''),
                $usages
            );
            throw new ToolExecutionException(
                'The file "' . $file->getName() . '" is still used and was not deleted: ' . implode(', ', $records)
                . '. Remove these usages first: a "sys_file_reference" with "delete_record", a link in a text'
                . ' field by changing the text.',
                1756802204
            );
        }
    }

    /**
     * @throws ToolExecutionException
     */
    private function deleteFile(File $file): void
    {
        try {
            $file->getStorage()->deleteFile($file);
        } catch (
            InsufficientFileAccessPermissionsException
            | InsufficientFolderAccessPermissionsException
            | IllegalFileExtensionException $exception
        ) {
            throw new ToolExecutionException(
                'The backend user is not allowed to delete the file "' . $file->getCombinedIdentifier() . '"',
                1756802208,
                $exception
            );
        } catch (Throwable $exception) {
            throw new ToolExecutionException(
                'The file "' . $file->getCombinedIdentifier() . '" could not be deleted: ' . $exception->getMessage(),
                1756802212,
                $exception
            );
        }
    }
}
