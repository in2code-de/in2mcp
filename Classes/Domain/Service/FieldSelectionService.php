<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\ToolExecutionException;

/**
 * Reduces a returned record to the fields a client asked for, so a list of records or the answer to an update does
 * not carry every field and every FlexForm along
 */
readonly class FieldSelectionService
{
    private const ALWAYS_RETURNED_FIELDS = ['uid', 'pid'];

    public function __construct(private TcaService $tcaService)
    {
    }

    /**
     * @param array<mixed> $fieldNames An empty list stands for every field
     * @return list<string>
     * @throws ToolExecutionException
     */
    public function getSelectedFields(string $table, array $fieldNames): array
    {
        $unknownFields = array_filter(
            $fieldNames,
            fn (mixed $fieldName): bool => is_string($fieldName) === false
                || $this->isKnownField($table, $fieldName) === false
        );
        if ($unknownFields !== []) {
            throw new ToolExecutionException(
                'There is no field ' . implode(', ', array_map('json_encode', $unknownFields)) . ' in "' . $table
                . '" to return. Call "get_schema" with the table to see its fields. Nothing was changed.',
                1764080100
            );
        }
        return array_values(array_unique($fieldNames));
    }

    /**
     * @param array<string, mixed>|null $record
     * @param list<string> $selectedFields
     * @return array<string, mixed>|null
     */
    public function selectFields(?array $record, array $selectedFields): ?array
    {
        $isSelecting = $record !== null && $selectedFields !== [];
        return $isSelecting
            ? array_intersect_key($record, array_flip([...self::ALWAYS_RETURNED_FIELDS, ...$selectedFields]))
            : $record;
    }

    private function isKnownField(string $table, string $fieldName): bool
    {
        return in_array($fieldName, self::ALWAYS_RETURNED_FIELDS, true)
            || $this->tcaService->isFieldOfTable($table, $fieldName);
    }
}
