<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Content;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Domain\Service\RecordAccessService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class SortContentElementsTool extends AbstractTool
{
    private const MINIMUM_ELEMENTS = 2;
    private const MAXIMUM_ELEMENTS = 200;

    /**
     * Fields that together are the place of a content element - every element that is sorted has to share them
     */
    private const POSITION_FIELDS = ['pid', 'colPos', 'sys_language_uid', ContentRepository::CONTAINER_PARENT_FIELD];

    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly ContentRepository $contentRepository,
        private readonly RecordAccessService $recordAccessService,
    ) {
    }

    public function getName(): string
    {
        return 'sort_content_elements';
    }

    public function getDescription(): string
    {
        return 'Sorts content elements of one column in a single call: the elements are placed in the given order,'
            . ' each behind the one before it. List every element of the column to get exactly this order -'
            . ' elements that are left out keep their place, the listed ones are gathered behind the first one.'
            . ' All elements have to be on the same page, in the same column, language and container. Use'
            . ' "move_record" to move an element into another column.';
    }

    public function getParameters(): array
    {
        return [
            'contentUids' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Uids of the content elements in their new order, ' . self::MINIMUM_ELEMENTS
                    . ' to ' . self::MAXIMUM_ELEMENTS,
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
        $contentUids = $this->getContentUids($this->getArrayArgument($arguments, 'contentUids'));
        $this->assertSharedPosition($this->findContentElements($contentUids));

        $previousUid = array_shift($contentUids);
        $sortedUids = [$previousUid];
        foreach ($contentUids as $contentUid) {
            $this->dataHandlerService->moveRecord(ContentRepository::TABLE_NAME, $contentUid, -$previousUid);
            $sortedUids[] = $contentUid;
            $previousUid = $contentUid;
        }

        return ['sorted' => true, 'contentUids' => $sortedUids];
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     * @throws ToolExecutionException
     */
    private function getContentUids(array $values): array
    {
        $uids = array_map('intval', array_values($values));
        $isValid = count($uids) >= self::MINIMUM_ELEMENTS
            && count($uids) <= self::MAXIMUM_ELEMENTS
            && min($uids) > 0
            && count(array_unique($uids)) === count($uids);
        if ($isValid === false) {
            throw new ToolExecutionException(
                '"contentUids" has to be a list of ' . self::MINIMUM_ELEMENTS . ' to ' . self::MAXIMUM_ELEMENTS
                . ' different uids of content elements',
                1764081600
            );
        }
        return $uids;
    }

    /**
     * @param list<int> $contentUids
     * @return list<array<string, mixed>>
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    private function findContentElements(array $contentUids): array
    {
        $contentElements = [];
        foreach ($contentUids as $contentUid) {
            $contentElement = $this->contentRepository->findByUid($contentUid);
            if ($contentElement === null
                || $this->recordAccessService->isRecordReadable(ContentRepository::TABLE_NAME, $contentElement)
                    === false
            ) {
                throw new ToolExecutionException(
                    'There is no content element with uid ' . $contentUid . ' that this backend user may see.'
                    . ' Nothing was sorted.',
                    1764081604
                );
            }
            $contentElements[] = $contentElement;
        }
        return $contentElements;
    }

    /**
     * @param list<array<string, mixed>> $contentElements
     * @throws ToolExecutionException
     */
    private function assertSharedPosition(array $contentElements): void
    {
        $positions = array_map(
            static fn (array $contentElement): string => implode(',', array_map(
                static fn (string $field): int => (int)($contentElement[$field] ?? 0),
                self::POSITION_FIELDS
            )),
            $contentElements
        );
        if (count(array_unique($positions)) > 1) {
            throw new ToolExecutionException(
                'The content elements are not all on the same page, in the same column, language and container.'
                . ' Sort each column on its own and use "move_record" to change the column. Nothing was sorted.',
                1764081608
            );
        }
    }
}
