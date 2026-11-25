<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Repository\ReferenceRepository;
use In2code\In2mcp\Domain\Service\PagedScanService;
use In2code\In2mcp\Domain\Service\RecordAccessService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class FindReferencesTool extends AbstractTool
{
    protected const READ_ONLY = true;

    private const DEFAULT_LIMIT = 100;
    private const MAXIMUM_LIMIT = 500;

    public function __construct(
        private readonly ReferenceRepository $referenceRepository,
        private readonly RecordRepository $recordRepository,
        private readonly TableAccessService $tableAccessService,
        private readonly RecordAccessService $recordAccessService,
        private readonly PagedScanService $pagedScanService,
    ) {
    }

    public function getName(): string
    {
        return 'find_references';
    }

    public function getDescription(): string
    {
        return 'Lists the records that point to a record, from the reference index of TYPO3: relations, file'
            . ' references, translations and links in texts like t3://page?uid=12. Call it before deleting a'
            . ' record or a page to find what would break. It also works for a record that is deleted already.'
            . ' References from records the user may not read are left out. When "nextOffset" is not null there'
            . ' are more: call it again with that value as "offset".';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Table of the record that is referenced, for example "pages"',
                'required' => true,
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Uid of the record that is referenced',
                'required' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of references to return, at most ' . self::MAXIMUM_LIMIT,
                'default' => self::DEFAULT_LIMIT,
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Where to continue a previous call: its "nextOffset"',
                'default' => 0,
            ],
        ];
    }

    /**
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $table = $this->getStringArgument($arguments, 'table');
        $uid = $this->getIntArgument($arguments, 'uid');
        $this->tableAccessService->assertReadable($table);

        $limit = $this->getIntArgument($arguments, 'limit');
        $limit = $limit < 1 ? self::DEFAULT_LIMIT : min($limit, self::MAXIMUM_LIMIT);
        $offset = max(0, $this->getIntArgument($arguments, 'offset'));
        $result = $this->pagedScanService->collect(
            fn (int $offset): array => $this->referenceRepository->findReferencesTo($table, $uid, $limit, $offset),
            fn (array $row): ?array => $this->getReadableReference($row),
            $limit,
            $offset
        );

        return [
            'table' => $table,
            'uid' => $uid,
            'count' => count($result['items']),
            'nextOffset' => $result['nextOffset'],
            'references' => $result['items'],
        ];
    }

    /**
     * A reference counts only when the user may read the table, the record and the field it is stored in. The
     * record is loaded cleaned up, so a field missing in it is one the user may not read.
     *
     * @param array{tablename: string, recuid: int, field: string, flexpointer: string, softref_key: string} $row
     * @return array<string, mixed>|null
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function getReadableReference(array $row): ?array
    {
        $record = $this->tableAccessService->isReadable($row['tablename'])
            ? $this->recordRepository->findByUid($row['tablename'], $row['recuid'])
            : null;
        $isReadable = $record !== null
            && array_key_exists($row['field'], $record)
            && $this->recordAccessService->isRecordReadable($row['tablename'], $record);

        return $isReadable ? [
            'table' => $row['tablename'],
            'uid' => $row['recuid'],
            'pid' => (int)($record['pid'] ?? 0),
            'field' => $row['field'],
            'flexFormPath' => $row['flexpointer'],
            'softReference' => $row['softref_key'],
        ] : null;
    }
}
