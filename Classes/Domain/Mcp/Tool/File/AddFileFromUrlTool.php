<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\FileImportService;
use In2code\In2mcp\Domain\Service\FileStorageService;
use In2code\In2mcp\Domain\Service\TargetFolderService;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Brings a file that does not exist here yet into the file storage. Everything afterwards is the normal way:
 * the file has a uid and "add_file_reference" connects it to a record.
 */
class AddFileFromUrlTool extends AbstractTool
{
    protected const DESTRUCTIVE = false;
    protected const OPEN_WORLD = true;

    public function __construct(
        private readonly FileImportService $fileImportService,
        private readonly TargetFolderService $targetFolderService,
        private readonly FileStorageService $fileStorageService,
    ) {
    }

    public function getName(): string
    {
        return 'add_file_from_url';
    }

    public function getDescription(): string
    {
        return 'Downloads a file from a public http(s) url into the file storage of this installation and'
            . ' returns its file uid, which "add_file_reference" needs to put it on a record. Only public'
            . ' addresses are accepted, and the installation can switch this off or restrict it to a list of'
            . ' hosts - a refusal is a setting of this installation, not something to work around.';
    }

    public function getParameters(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'description' => 'Public http or https url of the file',
                'required' => true,
            ],
            'targetFolder' => [
                'type' => 'string',
                'description' => 'Combined identifier of the folder the file is stored in, for example'
                    . ' "1:/user_upload/". Empty uses the default upload folder of the backend user.',
                'default' => '',
            ],
            'fileName' => [
                'type' => 'string',
                'description' => 'Name the file gets in the storage. Empty takes the name from the url. An'
                    . ' existing file of the same name is never overwritten, the new one is renamed.',
                'default' => '',
            ],
        ];
    }

    /**
     * @throws FileImportException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $url = $this->getStringArgument($arguments, 'url');
        $this->fileImportService->assertImportable($url);

        $folder = $this->targetFolderService->getWritableFolder($this->getStringArgument($arguments, 'targetFolder'));

        $file = $this->fileImportService->importFromUrl(
            $url,
            $folder,
            $this->getStringArgument($arguments, 'fileName')
        );

        return ['imported' => true] + $this->fileStorageService->describe($file, $folder);
    }
}
