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

        $this->assertInWebMount($record);

        return ['table' => $table, 'record' => $record];
    }

    /**
     * A record inherits the access of the page it is stored on. Records outside the page mounts of the backend
     * user are refused, exactly like the pages they belong to.
     *
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function assertInWebMount(array $record): void
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid === 0 || $this->backendUserService->isInWebMount($pid)) {
            return;
        }

        throw new ToolExecutionException(
            'The record is stored on page ' . $pid . ', which is outside the page mounts of this backend user',
            1756801024
        );
    }
}
