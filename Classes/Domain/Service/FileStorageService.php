<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\FileImportException;
use Throwable;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\SysLog\Action\File as SystemLogFileAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Folder::addFile() is ResourceStorage::addFile(), which applies file mounts, permissions and fileDenyPattern
class FileStorageService
{
    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly FileNameValidator $fileNameValidator,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    /**
     * @throws FileImportException
     */
    public function addFile(string $temporaryFile, Folder $folder, string $fileName, string $origin): File
    {
        $this->assertFileSize($temporaryFile);

        try {
            $file = $folder->addFile($temporaryFile, $fileName, DuplicationBehavior::RENAME);
        } catch (Throwable $exception) {
            throw new FileImportException(
                'The file could not be added to "' . $folder->getCombinedIdentifier() . '": '
                . $exception->getMessage(),
                1756801204,
                $exception
            );
        }

        $this->log(
            SystemLogErrorClassification::MESSAGE,
            'MCP: Imported "{origin}" as "{identifier}"',
            ['origin' => $origin, 'identifier' => $file->getIdentifier()]
        );
        return $file;
    }

    /**
     * @throws FileImportException
     */
    public function addContent(string $content, Folder $folder, string $fileName, string $origin): File
    {
        $temporaryFile = GeneralUtility::tempnam('in2mcp_upload_');

        try {
            if (file_put_contents($temporaryFile, $content) === false) {
                throw new FileImportException('The content could not be written to a temporary file', 1795028804);
            }
            return $this->addFile($temporaryFile, $folder, $fileName, $origin);
        } finally {
            GeneralUtility::unlink_tempfile($temporaryFile);
        }
    }

    /**
     * @throws FileImportException
     */
    public function getValidFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            throw new FileImportException(
                'No file name could be derived, pass "fileName" explicitly',
                1756801248
            );
        }

        if (str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            throw new FileImportException(
                'The file name must not contain a path, "' . $fileName . '" does',
                1756801252
            );
        }

        if ($this->fileNameValidator->isValid($fileName) === false) {
            throw new FileImportException(
                'The file name "' . $fileName . '" is refused by the fileDenyPattern of this installation',
                1756801256
            );
        }

        return $fileName;
    }

    /**
     * @return array{file: array<string, int|string>, folder: string}
     */
    public function describe(File $file, Folder $folder): array
    {
        return [
            'file' => [
                'uid' => $file->getUid(),
                'name' => $file->getName(),
                'identifier' => $file->getIdentifier(),
                'extension' => $file->getExtension(),
                'mimeType' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
            'folder' => $folder->getCombinedIdentifier(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(int $severity, string $message, array $context): void
    {
        try {
            $this->backendUserService->logFileAction(
                SystemLogFileAction::UPLOAD,
                $severity,
                $message,
                $context
            );
        } catch (Throwable) {
            // Without an authenticated user there is nothing to log against, and a failing log entry must
            // never turn into a failing import.
        }
    }

    /**
     * @throws FileImportException
     */
    private function assertFileSize(string $temporaryFile): void
    {
        clearstatcache(true, $temporaryFile);
        $size = (int)filesize($temporaryFile);
        $maximumSize = $this->configurationService->getFileImportMaximumSize();

        if ($size === 0) {
            throw new FileImportException('The file is empty', 1756801240);
        }
        if ($size > $maximumSize) {
            throw new FileImportException(
                'The file is larger than the allowed ' . $maximumSize . ' bytes',
                1756801236
            );
        }
    }
}
