<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class UpdateRecordTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordRepository $recordRepository,
    ) {
    }

    public function getName(): string
    {
        return 'update_record';
    }

    public function getDescription(): string
    {
        return 'Changes fields of a record of any table of this installation. Use "update_page" for pages and'
            . ' "update_content_element" for content elements. Only the given fields are written, every other'
            . ' field keeps its value.';
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
            'fields' => [
                'type' => 'object',
                'description' => 'Fields to change. Unknown field names are reported instead of ignored.',
                'required' => true,
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
        $uid = $this->getIntArgument($arguments, 'uid');

        $this->dataHandlerService->updateRecord($table, $uid, $this->getArrayArgument($arguments, 'fields'));

        return [
            'updated' => true,
            'table' => $table,
            'uid' => $uid,
            'record' => $this->recordRepository->findByUid($table, $uid),
        ];
    }
}
