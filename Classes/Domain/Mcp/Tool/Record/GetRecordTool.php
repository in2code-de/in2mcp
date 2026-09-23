<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class GetRecordTool extends AbstractTool
{
    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly TableAccessService $tableAccessService,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function getName(): string
    {
        return 'get_record';
    }

    public function getDescription(): string
    {
        return 'Reads a single record of any table of this installation. Use "get_page" for pages and their'
            . ' content elements.';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Name of the table the record belongs to',
                'required' => true,
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Uid of the record',
                'required' => true,
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
        $table = $this->getStringArgument($arguments, 'table');
        $uid = $this->getIntArgument($arguments, 'uid');
        $this->tableAccessService->assertReadable($table);

        $record = $this->recordRepository->findByUid($table, $uid);
        if ($record === null) {
            throw new ToolExecutionException(
                'There is no record with uid ' . $uid . ' in "' . $table . '"',
                1756801020
            );
        }

        if ($this->backendUserService->isRecordReachable($table, $record) === false) {
            throw new ToolExecutionException(
                'The record lies outside the page mounts of this backend user or on a page it may not read',
                1756801024
            );
        }

        return ['table' => $table, 'record' => $record];
    }
}
