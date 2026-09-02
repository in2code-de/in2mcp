<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Content;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;

class UpdateContentElementTool extends AbstractTool
{
    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly ContentRepository $contentRepository,
    ) {
    }

    public function getName(): string
    {
        return 'update_content_element';
    }

    public function getDescription(): string
    {
        return 'Changes fields of an existing content element. Only the given fields are written.';
    }

    public function getParameters(): array
    {
        return [
            'contentUid' => [
                'type' => 'integer',
                'description' => 'Uid of the content element to change',
                'required' => true,
            ],
            'fields' => [
                'type' => 'object',
                'description' => 'Fields to write, for example header, bodytext, colPos or hidden',
                'required' => true,
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $contentUid = $this->getIntArgument($arguments, 'contentUid');
        $this->dataHandlerService->updateRecord(
            ContentRepository::TABLE_NAME,
            $contentUid,
            $this->getArrayArgument($arguments, 'fields')
        );

        return [
            'updated' => true,
            'contentUid' => $contentUid,
            'contentElement' => $this->contentRepository->findByUid($contentUid),
        ];
    }
}
