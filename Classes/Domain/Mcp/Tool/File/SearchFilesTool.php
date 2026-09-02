<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\File;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\FileRepository;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * A client cannot upload files, so it has to find the files that already exist here before it can connect one
 * to a record.
 */
class SearchFilesTool extends AbstractTool
{
    public function __construct(
        private readonly FileRepository $fileRepository,
        private readonly TableAccessService $tableAccessService,
    ) {
    }

    public function getName(): string
    {
        return 'search_files';
    }

    public function getDescription(): string
    {
        return 'Searches files that already exist in this installation by a part of their name, their path or'
            . ' their title. Returns the file uid that "add_file_reference" needs. Files cannot be uploaded'
            . ' through MCP.';
    }

    public function getParameters(): array
    {
        return [
            'searchTerm' => [
                'type' => 'string',
                'description' => 'Part of the file name, the path or the title, for example "stage" or'
                    . ' "/fileadmin/campaigns/". An empty term returns the first files of the installation.',
                'default' => '',
            ],
            'fileExtension' => [
                'type' => 'string',
                'description' => 'Optional file extension to restrict the result, for example "jpg" or "pdf"',
                'default' => '',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of files to return',
                'default' => 25,
            ],
        ];
    }

    /**
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $this->tableAccessService->assertReadable(FileRepository::TABLE_NAME);

        $files = $this->fileRepository->findFiles(
            $this->getStringArgument($arguments, 'searchTerm'),
            $this->getStringArgument($arguments, 'fileExtension'),
            $this->getIntArgument($arguments, 'limit')
        );

        return ['count' => count($files), 'files' => $files];
    }
}
