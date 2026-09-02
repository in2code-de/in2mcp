<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class MoveRecordTool extends AbstractTool
{
    public function __construct(private readonly DataHandlerService $dataHandlerService)
    {
    }

    public function getName(): string
    {
        return 'move_record';
    }

    public function getDescription(): string
    {
        return 'Moves a record, either into a page or behind another record of the same table. Use it to sort'
            . ' pages in the tree or content elements within a column.';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Table of the record that is moved, for example "pages" or "tt_content"',
                'required' => true,
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Uid of the record to move',
                'required' => true,
            ],
            'intoPageUid' => [
                'type' => 'integer',
                'description' => 'Uid of the page the record is moved into. Use this or afterRecordUid.',
                'default' => 0,
            ],
            'afterRecordUid' => [
                'type' => 'integer',
                'description' => 'Uid of the record the moved record is placed behind. Use this or intoPageUid.',
                'default' => 0,
            ],
        ];
    }

    /**
     * @throws DataHandlerException
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $intoPageUid = $this->getIntArgument($arguments, 'intoPageUid');
        $afterRecordUid = $this->getIntArgument($arguments, 'afterRecordUid');

        if (($intoPageUid > 0) === ($afterRecordUid > 0)) {
            throw new ToolExecutionException(
                'Exactly one of "intoPageUid" and "afterRecordUid" must be given',
                1756800900
            );
        }

        $table = $this->getStringArgument($arguments, 'table');
        $uid = $this->getIntArgument($arguments, 'uid');
        $target = $intoPageUid > 0 ? $intoPageUid : -$afterRecordUid;
        $this->dataHandlerService->moveRecord($table, $uid, $target);

        return ['moved' => true, 'table' => $table, 'uid' => $uid, 'target' => $target];
    }
}
