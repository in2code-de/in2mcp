<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Model\Base64FileContent;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\FileStorageService;
use In2code\In2mcp\Domain\Service\FileUploadService;
use In2code\In2mcp\Domain\Service\TargetFolderService;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\FileUploadException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class AddFileFromContentTool extends AbstractTool
{
    protected const DESTRUCTIVE = false;

    private const ORIGIN = 'base64 content of the MCP client';

    public function __construct(
        private readonly ConfigurationService $configurationService,
        private readonly TargetFolderService $targetFolderService,
        private readonly FileStorageService $fileStorageService,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function getName(): string
    {
        return 'add_file_from_content';
    }

    public function getDescription(): string
    {
        return 'Stores a file whose content is passed base64 encoded in the file storage of this installation and'
            . ' returns its file uid, which "add_file_reference" needs to put it on a record. Meant for small files'
            . ' like icons, SVG or generated graphics - every character of the content has to be part of the call.'
            . ' A file that is reachable by a public url is better imported with "add_file_from_url". The'
            . ' installation can switch this off - a refusal is a setting, not something to work around.';
    }

    public function getParameters(): array
    {
        return [
            'content' => [
                'type' => 'string',
                'description' => 'Content of the file, base64 encoded. A data uri like'
                    . ' "data:image/png;base64,iVBOR..." is accepted as well.',
                'required' => true,
            ],
            'fileName' => [
                'type' => 'string',
                'description' => 'Name of the file including its extension, for example "logo.svg". An existing'
                    . ' file of the same name is never overwritten, the new one is renamed.',
                'required' => true,
            ],
            'targetFolder' => [
                'type' => 'string',
                'description' => 'Combined identifier of the folder the file is stored in, for example'
                    . ' "1:/user_upload/". Empty uses the default upload folder of the backend user.',
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
        $fileName = $this->fileStorageService->getValidFileName($this->getStringArgument($arguments, 'fileName'));

        $content = new Base64FileContent($this->getStringArgument($arguments, 'content'));
        $this->assertAllowedSize($content);

        $file = $this->fileStorageService->addContent($content->decode(), $folder, $fileName, self::ORIGIN);
        return ['imported' => true] + $this->fileStorageService->describe($file, $folder);
    }

    /**
     * Checked on the encoded content, so an oversized file is refused before it is decoded into memory
     *
     * @throws FileUploadException
     */
    private function assertAllowedSize(Base64FileContent $content): void
    {
        $maximumSize = $this->configurationService->getFileImportMaximumSize();
        if ($content->getDecodedSize() > $maximumSize) {
            throw new FileUploadException(
                'The file is larger than the allowed ' . $maximumSize . ' bytes',
                1795028812
            );
        }
    }
}
