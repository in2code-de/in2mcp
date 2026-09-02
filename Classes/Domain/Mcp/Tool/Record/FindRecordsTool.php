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

class FindRecordsTool extends AbstractTool
{
    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly TableAccessService $tableAccessService,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function getName(): string
    {
        return 'find_records';
    }

    public function getDescription(): string
    {
        return 'Lists the records of a table that are stored on a page. Use it to look at existing records'
            . ' before creating a comparable one, for example the form of a page that already works.';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Name of the table, for example "tx_powermail_domain_model_form"',
                'required' => true,
            ],
            'pid' => [
                'type' => 'integer',
                'description' => 'Uid of the page the records are stored on',
                'required' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of records to return',
                'default' => 50,
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
        $pid = $this->getIntArgument($arguments, 'pid');
        $this->tableAccessService->assertReadable($table);

        if ($pid > 0 && $this->backendUserService->isInWebMount($pid) === false) {
            throw new ToolExecutionException(
                'Page ' . $pid . ' is outside the page mounts of this backend user',
                1756801028
            );
        }

        $records = $this->recordRepository->findByPid($table, $pid, $this->getIntArgument($arguments, 'limit'));

        return [
            'table' => $table,
            'pid' => $pid,
            'count' => count($records),
            'records' => $records,
        ];
    }
}
