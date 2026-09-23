<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TableAccessService;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class FindRecordsTool extends AbstractTool
{
    private const ANY_PAGE = -1;
    private const ANY_LANGUAGE = -1;
    private const MAXIMUM_SCANNED_RECORDS = 5000;

    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly TableAccessService $tableAccessService,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    public function getName(): string
    {
        return 'find_records';
    }

    public function getDescription(): string
    {
        return 'Searches the records of a table by page, by field values and by language. Use it to look at an'
            . ' existing record before creating a comparable one, and to find a record whose page is unknown -'
            . ' searching by a field like {"form": 57} is the way to do that, not trying one page after the'
            . ' other. Records on pages outside the page mounts of the user are not returned.';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Name of the table, for example "tx_powermail_domain_model_page"',
                'required' => true,
            ],
            'pid' => [
                'type' => 'integer',
                'description' => 'Uid of the page the records are stored on. Leave it out to search the whole'
                    . ' installation.',
                'default' => self::ANY_PAGE,
            ],
            'filters' => [
                'type' => 'object',
                'description' => 'Field values that all have to match, for example {"form": 57} or'
                    . ' {"title": "Contact"}. Only fields that exist in the table are accepted.',
                'default' => [],
            ],
            'languageId' => [
                'type' => 'integer',
                'description' => 'Language of the records, 0 is the default language. Leave it out to get every'
                    . ' language.',
                'default' => self::ANY_LANGUAGE,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum number of records to return',
                'default' => 50,
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
        $this->tableAccessService->assertReadable($table);

        $pid = $this->getIntArgument($arguments, 'pid');
        if ($pid > 0 && $this->backendUserService->isInWebMount($pid) === false) {
            throw new ToolExecutionException(
                'Page ' . $pid . ' is outside the page mounts of this backend user',
                1756801028
            );
        }

        $languageId = $this->getIntArgument($arguments, 'languageId');
        $filters = $this->getArrayArgument($arguments, 'filters');
        $limit = $this->recordRepository->getLimit($this->getIntArgument($arguments, 'limit'));

        $findBatch = fn(int $offset): array => $this->recordRepository->find(
            $table,
            $pid === self::ANY_PAGE ? null : $pid,
            $filters,
            $languageId === self::ANY_LANGUAGE ? null : $languageId,
            $limit,
            $offset
        );
        $records = $this->collectReachableRecords($table, $findBatch, $limit);

        return [
            'table' => $table,
            'count' => count($records),
            'records' => array_values($records),
        ];
    }

    /**
     * @param callable(int): array<int, array<string, mixed>> $findBatch
     * @return array<int, array<string, mixed>>
     * @throws UserNotFoundException
     */
    private function collectReachableRecords(string $table, callable $findBatch, int $limit): array
    {
        $records = [];
        $offset = 0;
        do {
            $batch = $findBatch($offset);
            $offset += count($batch);
            $records = array_merge($records, $this->removeUnreachableRecords($table, $batch));
        } while (count($batch) === $limit && count($records) < $limit && $offset < self::MAXIMUM_SCANNED_RECORDS);

        return array_slice($records, 0, $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<string, mixed>>
     * @throws UserNotFoundException
     */
    private function removeUnreachableRecords(string $table, array $records): array
    {
        return array_filter(
            $records,
            fn(array $record): bool => $this->backendUserService->isRecordReachable($table, $record)
        );
    }
}
