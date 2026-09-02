<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\FileImportService;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Throwable;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Brings a file that does not exist here yet into the file storage. Everything afterwards is the normal way:
 * the file has a uid and "add_file_reference" connects it to a record.
 */
class AddFileFromUrlTool extends AbstractTool
{
    public function __construct(
        private readonly FileImportService $fileImportService,
        private readonly ResourceFactory $resourceFactory,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
        private readonly BackendUserService $backendUserService,
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

        $folder = $this->getTargetFolder($this->getStringArgument($arguments, 'targetFolder'));
        $this->assertFolderIsWritable($folder);

        $file = $this->fileImportService->importFromUrl(
            $url,
            $folder,
            $this->getStringArgument($arguments, 'fileName')
        );

        return [
            'imported' => true,
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
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function getTargetFolder(string $combinedIdentifier): Folder
    {
        if ($combinedIdentifier === '') {
            // resolve() is the entry point the backend uses as well: user TSconfig first, and if that says
            // nothing, the default folder of the first writable storage this user has access to.
            $folder = $this->defaultUploadFolderResolver->resolve($this->backendUserService->getBackendUser());

            if (($folder instanceof Folder) === false) {
                throw new ToolExecutionException(
                    'No default upload folder could be resolved for this backend user, so "targetFolder" has'
                    . ' to be given',
                    1756801300
                );
            }

            return $folder;
        }

        try {
            return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
        } catch (InsufficientFolderAccessPermissionsException $exception) {
            // The folder exists, this user just may not see it - which is a permission answer, not a missing
            // folder, and a client should not start guessing other paths because of it.
            throw new ToolExecutionException(
                'The backend user is not allowed to access the folder "' . $combinedIdentifier . '"',
                1756801304,
                $exception
            );
        } catch (Throwable $exception) {
            throw new ToolExecutionException(
                'The folder "' . $combinedIdentifier . '" could not be found: ' . $exception->getMessage(),
                1756801306,
                $exception
            );
        }
    }

    /**
     * The storage checks this again when the file is added, but a refusal is much easier to understand before
     * the download than as a failure afterwards.
     *
     * @throws ToolExecutionException
     */
    private function assertFolderIsWritable(Folder $folder): void
    {
        if ($folder->checkActionPermission('write') === false) {
            throw new ToolExecutionException(
                'The backend user is not allowed to write into "' . $folder->getCombinedIdentifier() . '"',
                1756801308
            );
        }
    }
}
