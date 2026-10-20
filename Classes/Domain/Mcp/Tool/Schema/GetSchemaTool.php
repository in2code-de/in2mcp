<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Schema;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\ContentTypeService;
use In2code\In2mcp\Domain\Service\FieldItemService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Every TYPO3 installation has its own page types, content types, tables and fields. This tool reports what
 * exists here, so a client does not have to guess names when writing records.
 */
class GetSchemaTool extends AbstractTool
{
    /**
     * Fields whose values are listed as "pageTypes" and "contentTypes" already, so they are not repeated as items
     */
    private const FIELDS_WITHOUT_ITEMS = [
        PageRepository::TABLE_NAME => ['doktype'],
        ContentRepository::TABLE_NAME => ['CType'],
    ];

    public function __construct(
        private readonly TcaService $tcaService,
        private readonly TableAccessService $tableAccessService,
        private readonly ContentTypeService $contentTypeService,
        private readonly FieldItemService $fieldItemService,
        private readonly PageRepository $pageRepository,
    ) {
    }

    public function getName(): string
    {
        return 'get_schema';
    }

    public function getDescription(): string
    {
        return 'Returns the page types, content element types and fields of this TYPO3 installation, plus the'
            . ' tables the connected backend user may write. Pass a table name to get the fields of that table,'
            . ' for example before using "create_record". Call this before writing records, because the'
            . ' available types, tables and fields differ from installation to installation. Pass the uid of the'
            . ' page you are going to work on as "pageUid": the content element types an editor may choose differ'
            . ' from page to page, and only that list is valid for "create_content_element". "defaultContentType"'
            . ' is the type the backend preselects for a new element - the type of choice for plain text. Select,'
            . ' radio and multi checkbox fields list their allowed values as "items" [{value, label}] - write the'
            . ' value, never the label. The values of a multi checkbox are bits, combine them by adding them up.'
            . ' Values can differ per page and per type, so pass "pageUid" and "recordType" (for content elements'
            . ' the content type, for example "textmedia") to get exactly the values an editor sees in the backend'
            . ' form. Fields marked "disabled" are hidden from the editors of that page. Select fields that point to'
            . ' records of another table list no items. The values of "CType" and "doktype" are the "contentTypes"'
            . ' and "pageTypes" of the call without "table".';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Optional table name. When given, only the fields of that table are returned,'
                    . ' for example "tx_powermail_domain_model_form". Fields of type "file" can be filled with'
                    . ' "add_file_reference".',
                'default' => '',
            ],
            'pageUid' => [
                'type' => 'integer',
                'description' => 'Optional uid of the page records are going to be created or changed on. The content'
                    . ' element types and the items of the fields are then restricted by the page TSconfig of that'
                    . ' page, exactly as an editor sees them in the backend. Without it every type and every value'
                    . ' registered in the installation is listed, including those this installation hides from its'
                    . ' editors.',
                'default' => 0,
            ],
            'recordType' => [
                'type' => 'string',
                'description' => 'Optional record type of "table" - without "table" of a content element - for'
                    . ' example the content type "textmedia" of "tt_content" or the page type "1" of "pages". Only'
                    . ' the fields of that type are returned then, with the overrides of that type applied to their'
                    . ' labels and items, including the type specific page TSconfig of "pageUid".',
                'default' => '',
            ],
            'withFields' => [
                'type' => 'boolean',
                'description' => 'Whether the field definitions of pages and content elements are included',
                'default' => true,
            ],
            'withTables' => [
                'type' => 'boolean',
                'description' => 'Whether the list of writable tables is included',
                'default' => true,
            ],
        ];
    }

    /**
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $table = $this->getStringArgument($arguments, 'table');
        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        $recordType = $this->getStringArgument($arguments, 'recordType');

        if ($pageUid > 0 && $this->pageRepository->findByUid($pageUid) === null) {
            throw new ToolExecutionException(
                'There is no page with uid ' . $pageUid . ' or the backend user may not see it',
                1756802000
            );
        }

        if ($table !== '') {
            $this->tableAccessService->assertReadable($table);
            $this->assertRecordType($table, $recordType);
            $schema = $this->getTableSchema($table, $pageUid, $recordType);
        } else {
            $this->assertRecordType(ContentRepository::TABLE_NAME, $recordType);
            $schema = $this->getOverview($arguments, $pageUid, $recordType);
        }

        return $schema;
    }

    /**
     * @throws ToolExecutionException
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     */
    private function getOverview(array $arguments, int $pageUid, string $contentType): array
    {
        $schema = [
            'pageTypes' => $this->tcaService->getPageTypes(),
            'contentTypes' => $this->contentTypeService->getContentTypes($pageUid),
            'defaultContentType' => $this->contentTypeService->getDefaultContentType($pageUid),
        ];

        if ((bool)$this->getArgument($arguments, 'withFields')) {
            $schema['fields'] = [
                PageRepository::TABLE_NAME => $this->getFields(PageRepository::TABLE_NAME, $pageUid, ''),
                ContentRepository::TABLE_NAME => $this->getFields(
                    ContentRepository::TABLE_NAME,
                    $pageUid,
                    $contentType
                ),
            ];
        }

        if ((bool)$this->getArgument($arguments, 'withTables')) {
            $schema['writableTables'] = $this->tableAccessService->getWritableTables();
        }

        return $schema;
    }

    /**
     * @throws UserNotFoundException
     */
    private function getTableSchema(string $table, int $pageUid, string $recordType): array
    {
        $schema = [
            'table' => $table,
            'label' => $this->tcaService->getTableLabel($table),
            'sortable' => $this->tcaService->getSortingField($table) !== null,
        ];
        if ($recordType !== '') {
            $schema['recordType'] = $recordType;
        }
        $schema['fields'] = $this->getFields($table, $pageUid, $recordType);

        return $schema;
    }

    /**
     * @throws UserNotFoundException
     */
    private function getFields(string $table, int $pageUid, string $recordType): array
    {
        return $this->fieldItemService->addItemsToFields(
            $table,
            $this->tcaService->getFields($table, $recordType),
            $pageUid,
            $recordType,
            self::FIELDS_WITHOUT_ITEMS[$table] ?? []
        );
    }

    /**
     * @throws ToolExecutionException
     */
    private function assertRecordType(string $table, string $recordType): void
    {
        if ($recordType !== '' && $this->tcaService->hasRecordType($table, $recordType) === false) {
            throw new ToolExecutionException(
                'There is no record type "' . $recordType . '" in "' . $table . '". Call "get_schema" without'
                . ' "recordType" to see the available types.',
                1756802100
            );
        }
    }
}
