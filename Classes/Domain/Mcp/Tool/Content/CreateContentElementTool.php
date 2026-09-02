<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Content;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;

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
            . ' are used there.';
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
                    . ' "position" when greater than 0.',
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

    public function execute(array $arguments): array
    {
        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        $afterContentUid = $this->getIntArgument($arguments, 'afterContentUid');

        $fields = $this->getArrayArgument($arguments, 'fields');
        $fields['CType'] = $this->getStringArgument($arguments, 'contentType');
        $fields['colPos'] = $this->getIntArgument($arguments, 'colPos');

        $uid = $this->dataHandlerService->createRecord(
            ContentRepository::TABLE_NAME,
            $this->getPid(
                $pageUid,
                (int)$fields['colPos'],
                $afterContentUid,
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
    private function getPid(int $pageUid, int $colPos, int $afterContentUid, string $position): int
    {
        if ($afterContentUid > 0) {
            return -$afterContentUid;
        }

        if ($position === 'start') {
            return $pageUid;
        }

        $lastUid = $this->contentRepository->findLastUidInColumn($pageUid, $colPos);
        return $lastUid > 0 ? -$lastUid : $pageUid;
    }
}
