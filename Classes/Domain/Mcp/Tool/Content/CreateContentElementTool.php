<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Content;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Exception\ToolExecutionException;

class CreateContentElementTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function getName(): string
    {
        return 'create_content_element';
    }

    public function getDescription(): string
    {
        return 'Creates a content element on a page. Call "get_schema" for the content element types of this'
            . ' installation and read a comparable page with "get_page" to see which types and column positions'
            . ' are used there. To put an element inside a container element, pass the uid of the container as'
            . ' "containerParentUid" together with the column position of that container.';
    }

    public function getParameters(): array
    {
        return [
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Uid of the page the content element is created on',
                'required' => true,
            ],
            'contentType' => [
                'type' => 'string',
                'description' => 'Type of the content element, for example "textmedia", "text" or "header"',
                'required' => true,
            ],
            'colPos' => [
                'type' => 'integer',
                'description' => 'Column position of the content element, 0 is the main column',
                'default' => 0,
            ],
            'position' => [
                'type' => 'string',
                'description' => 'Whether the element is appended at the end or put at the start of the column',
                'enum' => ['end', 'start'],
                'default' => 'end',
            ],
            'afterContentUid' => [
                'type' => 'integer',
                'description' => 'Optional uid of a content element the new one is inserted behind. Overrides'
                    . ' "position" when greater than 0. It has to be an element of the same column, a container'
                    . ' element is not a valid sibling for its own children.',
                'default' => 0,
            ],
            'containerParentUid' => [
                'type' => 'integer',
                'description' => 'Uid of the container element the new element is placed in. Requires the'
                    . ' column position of the target column inside that container in "colPos".',
                'default' => 0,
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Fields of the content element, for example header, subheader, bodytext or'
                    . ' header_layout',
                'default' => [],
            ],
        ];
    }

    /**
     * @throws ToolExecutionException
     */
    public function execute(array $arguments): array
    {
        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        $afterContentUid = $this->getIntArgument($arguments, 'afterContentUid');
        $containerParentUid = $this->getIntArgument($arguments, 'containerParentUid');

        $fields = $this->getArrayArgument($arguments, 'fields');
        $fields['CType'] = $this->getStringArgument($arguments, 'contentType');
        $fields['colPos'] = $this->getIntArgument($arguments, 'colPos');

        if ($containerParentUid > 0) {
            $this->assertContainerIsUsable($containerParentUid);
            $fields[ContentRepository::CONTAINER_PARENT_FIELD] = $containerParentUid;
        }

        $uid = $this->dataHandlerService->createRecord(
            ContentRepository::TABLE_NAME,
            $this->getPid(
                $pageUid,
                (int)$fields['colPos'],
                $afterContentUid,
                $containerParentUid,
                $this->getStringArgument($arguments, 'position')
            ),
            $fields
        );

        return [
            'created' => true,
            'contentUid' => $uid,
            'contentElement' => $this->contentRepository->findByUid($uid),
        ];
    }

    /**
     * DataHandler puts a record with a positive pid at the very beginning of the page. To append an element at
     * the end of its column, it has to be inserted behind the last existing element of that column, which is
     * expressed by a negative pid.
     */
    private function getPid(
        int $pageUid,
        int $colPos,
        int $afterContentUid,
        int $containerParentUid,
        string $position
    ): int {
        if ($afterContentUid > 0) {
            return -$afterContentUid;
        }

        if ($position === 'start') {
            return $pageUid;
        }

        $lastUid = $this->contentRepository->findLastUidInColumn($pageUid, $colPos, $containerParentUid);
        return $lastUid > 0 ? -$lastUid : $pageUid;
    }

    /**
     * A container that does not exist, or a container field that does not exist because b13/container is not
     * installed, would silently produce an element that no backend layout ever renders.
     *
     * @throws ToolExecutionException
     */
    private function assertContainerIsUsable(int $containerParentUid): void
    {
        if ($this->contentRepository->isContainerInstalled() === false) {
            throw new ToolExecutionException(
                'This installation has no container elements, so "containerParentUid" cannot be used',
                1756801040
            );
        }

        if ($this->contentRepository->findByUid($containerParentUid) === null) {
            throw new ToolExecutionException(
                'There is no content element with uid ' . $containerParentUid . ' to use as container',
                1756801044
            );
        }
    }
}
