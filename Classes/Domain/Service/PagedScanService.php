<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

/**
 * Collects a page of results from rows that are read in batches and filtered afterwards, typically by the
 * permissions of the user. The offset counts the rows, the filtered ones included, so a following call continues
 * exactly after the last row that was looked at.
 */
class PagedScanService
{
    private const MAXIMUM_SCANNED_ROWS = 5000;

    /**
     * @param callable(int $offset): array<int, array<string, mixed>> $findBatch Reads up to $limit rows
     * @param callable(array<string, mixed> $row): (array<string, mixed>|null) $convert Null drops the row
     * @return array{items: list<array<string, mixed>>, nextOffset: ?int}
     */
    public function collect(callable $findBatch, callable $convert, int $limit, int $offset): array
    {
        $items = [];
        $scanLimit = $offset + self::MAXIMUM_SCANNED_ROWS;
        do {
            $batch = $findBatch($offset);
            $isLastBatch = count($batch) < $limit;
            foreach ($batch as $row) {
                if (count($items) === $limit) {
                    $isLastBatch = false;
                    break;
                }
                $offset++;
                $item = $convert($row);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        } while ($isLastBatch === false && count($items) < $limit && $offset < $scanLimit);

        return ['items' => $items, 'nextOffset' => $isLastBatch ? null : $offset];
    }
}
