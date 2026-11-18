<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Model\UploadToken;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\FileUploadException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FileUploadService
{
    public const TOKEN_HEADER = 'X-In2mcp-Upload-Token';
    public const FILE_FIELD = 'file';

    private const ORIGIN = 'upload of the MCP client';

    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly TargetFolderService $targetFolderService,
        private readonly FileStorageService $fileStorageService,
    ) {
    }

    /**
     * @throws FileUploadException
     */
    public function assertUploadIsActivated(): void
    {
        if ($this->configurationService->isFileUploadActivated() === false) {
            throw new FileUploadException(
                'Uploading files is switched off in the extension configuration of in2mcp',
                1795028808
            );
        }
    }

    /**
     * @return array{file: array<string, int|string>, folder: string}
     * @throws FileImportException
     * @throws FileUploadException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function storeUploadedFile(?UploadedFileInterface $uploadedFile, UploadToken $uploadToken): array
    {
        $this->assertUploadIsActivated();
        $uploadedFile = $this->getValidUploadedFile($uploadedFile);

        // Resolved again instead of trusting the check when the token was created - permissions may have changed
        $folder = $this->targetFolderService->getWritableFolder($uploadToken->getFolder());
        $fileName = $uploadToken->getFileName() !== ''
            ? $uploadToken->getFileName()
            : (string)$uploadedFile->getClientFilename();
        $fileName = $this->fileStorageService->getValidFileName($fileName);

        $temporaryFile = GeneralUtility::tempnam('in2mcp_upload_');
        try {
            $this->moveUploadedFile($uploadedFile, $temporaryFile);
            $file = $this->fileStorageService->addFile($temporaryFile, $folder, $fileName, self::ORIGIN);
        } finally {
            GeneralUtility::unlink_tempfile($temporaryFile);
        }

        return $this->fileStorageService->describe($file, $folder);
    }

    /**
     * @throws FileUploadException
     */
    private function getValidUploadedFile(?UploadedFileInterface $uploadedFile): UploadedFileInterface
    {
        if ($uploadedFile === null) {
            throw new FileUploadException(
                'The request contains no file. Send it as multipart/form-data in the field "' . self::FILE_FIELD
                . '", for example with curl -F \'' . self::FILE_FIELD . '=@/path/to/file\'.',
                1795028816
            );
        }

        $maximumSize = $this->configurationService->getFileImportMaximumSize();
        $isTooLarge = in_array($uploadedFile->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || (int)$uploadedFile->getSize() > $maximumSize;
        if ($isTooLarge) {
            throw new FileUploadException(
                'The file is larger than the allowed ' . $maximumSize . ' bytes or than the upload limit of PHP',
                1795028820
            );
        }

        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new FileUploadException(
                'The upload failed with the PHP upload error ' . $uploadedFile->getError(),
                1795028824
            );
        }

        return $uploadedFile;
    }

    /**
     * @throws FileUploadException
     */
    private function moveUploadedFile(UploadedFileInterface $uploadedFile, string $temporaryFile): void
    {
        try {
            $uploadedFile->moveTo($temporaryFile);
        } catch (Throwable $exception) {
            throw new FileUploadException('The uploaded file could not be read', 1795028828, $exception);
        }
    }
}
