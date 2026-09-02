<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class MoveRecordTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function getName(): string
    {
        return 'move_record';
    }

    public function getDescription(): string
    {
        return 'Moves a record, either into a page or behind another record of the same table. Use it to sort'
            . ' pages in the tree or content elements within a column. A content element changes its column or'
            . ' its container in the same step, by passing "colPos" and "containerParentUid" along with the'
            . ' move - the target alone only decides the position in the sorting.';
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
                'description' => 'Uid of the record the moved record is placed behind. Use this or intoPageUid.'
                    . ' It has to be a record of the same table in the same column.',
                'default' => 0,
            ],
            'colPos' => [
                'type' => 'integer',
                'description' => 'Content elements only: column position the element gets after the move. Pass'
                    . ' it whenever the element changes its column, otherwise it keeps the old one.',
                'default' => -1,
            ],
            'containerParentUid' => [
                'type' => 'integer',
                'description' => 'Content elements only: uid of the container the element belongs to after the'
                    . ' move, or 0 to take it out of its container. Only written when "colPos" is given as'
                    . ' well, because a container position needs both.',
                'default' => -1,
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
        $update = $this->getUpdate($table, $arguments);

        $this->dataHandlerService->moveRecord($table, $uid, $target, $update);

        return [
            'moved' => true,
            'table' => $table,
            'uid' => $uid,
            'target' => $target,
            'update' => $update,
            'record' => $table === ContentRepository::TABLE_NAME
                ? $this->contentRepository->findByUid($uid)
                : null,
        ];
    }

    /**
     * Fields that travel with the move. The column and the container are ordinary fields, so a move that does
     * not carry them leaves the element in its old column - which looks like a move that did not work.
     *
     * @return array<string, int>
     * @throws ToolExecutionException
     */
    private function getUpdate(string $table, array $arguments): array
    {
        $colPos = $this->getIntArgument($arguments, 'colPos');
        $containerParentUid = $this->getIntArgument($arguments, 'containerParentUid');

        if ($colPos < 0 && $containerParentUid < 0) {
            return [];
        }

        if ($table !== ContentRepository::TABLE_NAME) {
            throw new ToolExecutionException(
                '"colPos" and "containerParentUid" only exist for content elements, not for "' . $table . '"',
                1756801048
            );
        }

        if ($colPos < 0) {
            throw new ToolExecutionException(
                '"containerParentUid" needs "colPos" as well, because a position inside a container is the'
                . ' combination of both',
                1756801052
            );
        }

        $update = ['colPos' => $colPos];
        if ($containerParentUid >= 0) {
            if ($containerParentUid > 0 && $this->contentRepository->isContainerInstalled() === false) {
                throw new ToolExecutionException(
                    'This installation has no container elements, so "containerParentUid" cannot be used',
                    1756801056
                );
            }
            if ($this->contentRepository->isContainerInstalled()) {
                $update[ContentRepository::CONTAINER_PARENT_FIELD] = $containerParentUid;
            }
        }

        return $update;
    }
}
