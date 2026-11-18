<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use DateTimeImmutable;
use DateTimeInterface;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\EndpointUrlService;
use In2code\In2mcp\Domain\Service\FileStorageService;
use In2code\In2mcp\Domain\Service\FileUploadService;
use In2code\In2mcp\Domain\Service\TargetFolderService;
use In2code\In2mcp\Domain\Service\UploadTokenService;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\FileUploadException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class CreateFileUploadTool extends AbstractTool
{
    protected const DESTRUCTIVE = false;

    private const FILE_PLACEHOLDER = '/path/to/file';

    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly TargetFolderService $targetFolderService,
        private readonly FileStorageService $fileStorageService,
        private readonly UploadTokenService $uploadTokenService,
        private readonly EndpointUrlService $endpointUrlService,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function getName(): string
    {
        return 'create_file_upload';
    }

    public function getDescription(): string
    {
        return 'Prepares the upload of a local file of any size for a client that can send http requests itself,'
            . ' for example Claude Code with a shell. Returns an upload url and a token that is valid once and for'
            . ' ' . (UploadTokenService::LIFETIME / 60) . ' minutes, plus a ready curl command. The answer of the'
            . ' upload contains the file uid, which "add_file_reference" needs to put the file on a record. A client'
            . ' without a shell uses "add_file_from_content" for small files or "add_file_from_url" instead.';
    }

    public function getParameters(): array
    {
        return [
            'targetFolder' => [
                'type' => 'string',
                'description' => 'Combined identifier of the folder the file is stored in, for example'
                    . ' "1:/user_upload/". Empty uses the default upload folder of the backend user.',
                'default' => '',
            ],
            'fileName' => [
                'type' => 'string',
                'description' => 'Name the file gets in the storage. Empty takes the name of the uploaded file. An'
                    . ' existing file of the same name is never overwritten, the new one is renamed.',
                'default' => '',
            ],
        ];
    }

    /**
     * @throws FileImportException
     * @throws FileUploadException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $this->fileUploadService->assertUploadIsActivated();
        $folder = $this->targetFolderService->getWritableFolder($this->getStringArgument($arguments, 'targetFolder'));
        $fileName = $this->getStringArgument($arguments, 'fileName');
        if ($fileName !== '') {
            $fileName = $this->fileStorageService->getValidFileName($fileName);
        }

        $token = $this->uploadTokenService->create(
            $this->backendUserService->getUserId(),
            $folder->getCombinedIdentifier(),
            $fileName
        );
        $uploadUrl = $this->endpointUrlService->getFileUploadUrl();

        return [
            'uploadUrl' => $uploadUrl,
            'method' => 'POST',
            'tokenHeader' => FileUploadService::TOKEN_HEADER,
            'token' => $token,
            'fileField' => FileUploadService::FILE_FIELD,
            'expiresAt' => (new DateTimeImmutable('@' . (time() + UploadTokenService::LIFETIME)))
                ->format(DateTimeInterface::ATOM),
            'folder' => $folder->getCombinedIdentifier(),
            'curl' => sprintf(
                'curl -sS -X POST -H %s -F %s %s',
                escapeshellarg(FileUploadService::TOKEN_HEADER . ': ' . $token),
                escapeshellarg(FileUploadService::FILE_FIELD . '=@' . self::FILE_PLACEHOLDER),
                escapeshellarg($uploadUrl)
            ),
            'hint' => 'Replace ' . self::FILE_PLACEHOLDER . ' with the path of the local file.',
        ];
    }
}
