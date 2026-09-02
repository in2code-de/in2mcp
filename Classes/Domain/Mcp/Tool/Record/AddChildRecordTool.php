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
 * Records that only exist inside another record - the pages of a form, the fields of such a page - are created
 * through their parent, never on their own.
 */
class AddChildRecordTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordRepository $recordRepository,
        private readonly TcaService $tcaService,
    ) {
    }

    public function getName(): string
    {
        return 'add_child_record';
    }

    public function getDescription(): string
    {
        return 'Creates a record inside an inline field of another record, for example a page of a form or a'
            . ' field of such a page. Use this instead of "create_record" whenever the new record belongs into'
            . ' a field of a parent: it writes the child and the list of the parent in one step, which is what'
            . ' keeps the child counter of the parent correct. A child created with "create_record" leaves that'
            . ' counter at zero, and the parent then behaves as if it had no children at all.';
    }

    public function getParameters(): array
    {
        return [
            'parentTable' => [
                'type' => 'string',
                'description' => 'Table of the parent record, for example "tx_powermail_domain_model_form"',
                'required' => true,
            ],
            'parentUid' => [
                'type' => 'integer',
                'description' => 'Uid of the parent record',
                'required' => true,
            ],
            'parentField' => [
                'type' => 'string',
                'description' => 'Inline field of the parent the child is added to, for example "pages" or'
                    . ' "fields". "get_schema" shows such fields with type "inline".',
                'required' => true,
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Fields of the child record. The field that points back at the parent is set'
                    . ' automatically and does not belong here.',
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
        $parentTable = $this->getStringArgument($arguments, 'parentTable');
        $parentUid = $this->getIntArgument($arguments, 'parentUid');
        $parentField = $this->getStringArgument($arguments, 'parentField');

        $parent = $this->recordRepository->findByUid($parentTable, $parentUid);
        if ($parent === null) {
            throw new ToolExecutionException(
                'There is no record with uid ' . $parentUid . ' in "' . $parentTable . '"',
                1756801520
            );
        }

        $relation = $this->tcaService->getInlineRelation($parentTable, $parentField);
        $uid = $this->dataHandlerService->createChildRecord(
            $parentTable,
            $parentUid,
            $parentField,
            (int)($parent['pid'] ?? 0),
            $this->getArrayArgument($arguments, 'fields')
        );

        return [
            'created' => true,
            'table' => $relation['foreignTable'] ?? '',
            'uid' => $uid,
            'record' => $this->recordRepository->findByUid($relation['foreignTable'] ?? '', $uid),
            'parent' => $this->recordRepository->findByUid($parentTable, $parentUid),
        ];
    }
}
