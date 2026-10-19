<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Schema;

use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Service\ContentTypeService;
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
    public function __construct(
        private readonly TcaService $tcaService,
        private readonly TableAccessService $tableAccessService,
        private readonly ContentTypeService $contentTypeService,
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
            . ' from page to page, and only that list is valid for "create_content_element".';
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
                'description' => 'Optional uid of the page content elements are going to be created on. The content'
                    . ' element types are then restricted by the page TSconfig of that page, exactly as an editor'
                    . ' sees them in the backend. Without it every type registered in the installation is listed,'
                    . ' including types this installation hides from its editors.',
                'default' => 0,
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
        if ($table !== '') {
            return $this->getTableSchema($table);
        }

        $pageUid = $this->getIntArgument($arguments, 'pageUid');
        if ($pageUid > 0 && $this->pageRepository->findByUid($pageUid) === null) {
            throw new ToolExecutionException(
                'There is no page with uid ' . $pageUid . ' or the backend user may not see it',
                1756802000
            );
        }

        $schema = [
            'pageTypes' => $this->tcaService->getPageTypes(),
            'contentTypes' => $this->contentTypeService->getContentTypes($pageUid),
        ];

        if ((bool)$this->getArgument($arguments, 'withFields')) {
            $schema['fields'] = [
                PageRepository::TABLE_NAME => $this->tcaService->getFields(PageRepository::TABLE_NAME),
                ContentRepository::TABLE_NAME => $this->tcaService->getFields(ContentRepository::TABLE_NAME),
            ];
        }

        if ((bool)$this->getArgument($arguments, 'withTables')) {
            $schema['writableTables'] = $this->tableAccessService->getWritableTables();
        }

        return $schema;
    }

    /**
     * @throws TableNotAccessibleException
     * @throws UserNotFoundException
     */
    private function getTableSchema(string $table): array
    {
        $this->tableAccessService->assertReadable($table);

        return [
            'table' => $table,
            'label' => $this->tcaService->getTableLabel($table),
            'sortable' => $this->tcaService->getSortingField($table) !== null,
            'fields' => $this->tcaService->getFields($table),
        ];
    }
}
