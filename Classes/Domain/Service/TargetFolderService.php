<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Throwable;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class TargetFolderService
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    /**
     * The storage checks the permissions again when the file is added, but a refusal is much easier to understand
     * before a download or an upload than as a failure afterwards.
     *
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function getWritableFolder(string $combinedIdentifier): Folder
    {
        $folder = $this->getFolder($combinedIdentifier);
        $this->assertRegularStorage($folder);
        $this->assertFolderIsWritable($folder);
        return $folder;
    }

    /**
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function getFolder(string $combinedIdentifier): Folder
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
     * @throws ToolExecutionException
     */
    private function assertRegularStorage(Folder $folder): void
    {
        // Storage 0 is the web root and evaluates no permissions - the core refuses it for every user as well
        if ($folder->getStorage()->isFallbackStorage()) {
            throw new ToolExecutionException(
                'Storage 0 holds files outside of every file storage and is no target for a new file. Use a folder'
                . ' of a file storage like "1:/user_upload/", or leave "targetFolder" empty for the default upload'
                . ' folder of the backend user.',
                1756801310
            );
        }
    }

    /**
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
