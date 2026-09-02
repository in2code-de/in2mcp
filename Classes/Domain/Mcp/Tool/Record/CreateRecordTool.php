<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Creates a record of any table the backend user may write. Pages and content elements have their own tools,
 * because they need the tree and the column logic; this one covers everything else an extension brings along,
 * for example a form, a news entry or an address.
 */
class CreateRecordTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordRepository $recordRepository,
        private readonly TcaService $tcaService,
    ) {
    }

    public function getName(): string
    {
        return 'create_record';
    }

    public function getDescription(): string
    {
        return 'Creates a record of any table of this installation, for example a form, a news entry or an'
            . ' address. Use "create_page" for pages and "create_content_element" for content elements. Call'
            . ' "get_schema" with the table name first to learn its fields, and look at a comparable existing'
            . ' record with "find_records" to follow the conventions of this installation.';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Name of the table, for example "tx_news_domain_model_news". Call "get_schema"'
                    . ' to see the tables this user may write.',
                'required' => true,
            ],
            'pid' => [
                'type' => 'integer',
                'description' => 'Uid of the page the record is stored on',
                'required' => true,
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Fields of the record. Unknown field names are reported instead of ignored.',
                'required' => true,
            ],
            'position' => [
                'type' => 'string',
                'description' => 'Whether a sortable record is appended at the end or put at the start of the'
                    . ' page. Tables without manual sorting ignore this.',
                'enum' => ['end', 'start'],
                'default' => 'end',
            ],
        ];
    }

    /**
     * @throws DataHandlerException
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $table = $this->getStringArgument($arguments, 'table');
        $pid = $this->getIntArgument($arguments, 'pid');
        $fields = $this->getArrayArgument($arguments, 'fields');

        $uid = $this->dataHandlerService->createRecord(
            $table,
            $this->getPid($table, $pid, $this->getStringArgument($arguments, 'position')),
            $fields
        );

        return [
            'created' => true,
            'table' => $table,
            'uid' => $uid,
            'record' => $this->recordRepository->findByUid($table, $uid),
        ];
    }

    /**
     * DataHandler puts a record with a positive pid at the very beginning of the page. Appending it therefore
     * means inserting it behind the last existing sibling, which is expressed by a negative pid.
     *
     * @throws Exception
     */
    private function getPid(string $table, int $pid, string $position): int
    {
        if ($position === 'start' || $this->tcaService->getSortingField($table) === null) {
            return $pid;
        }

        $lastUid = $this->recordRepository->findLastUidOnPid($table, $pid);
        return $lastUid > 0 ? -$lastUid : $pid;
    }
}
