<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\DataHandlerService;

class DeleteRecordTool extends AbstractTool
{
    public function __construct(private readonly DataHandlerService $dataHandlerService)
    {
    }

    public function getName(): string
    {
        return 'delete_record';
    }

    public function getDescription(): string
    {
        return 'Deletes a record of any table. TYPO3 only flags the record as deleted, so an editor can still'
            . ' restore it from the recycler. Deleting a page deletes its subpages and content as well.';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Table of the record that is deleted, for example "pages" or "tt_content"',
                'required' => true,
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Uid of the record',
                'required' => true,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $table = $this->getStringArgument($arguments, 'table');
        $uid = $this->getIntArgument($arguments, 'uid');
        $this->dataHandlerService->deleteRecord($table, $uid);

        return ['deleted' => true, 'table' => $table, 'uid' => $uid];
    }
}
