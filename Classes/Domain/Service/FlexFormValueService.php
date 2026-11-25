<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\ToolExecutionException;

/**
 * Lets a client write single FlexForm fields as {"sDEF": {"settings.limit": 5}}. The DataHandler merges an array
 * into the stored FlexForm, so the fields that are not named keep their values - XML as a string would replace the
 * whole FlexForm instead.
 */
readonly class FlexFormValueService
{
    private const DATA_KEY = 'data';
    private const LANGUAGE_KEY = 'lDEF';
    private const VALUE_KEY = 'vDEF';

    public function __construct(private TcaService $tcaService)
    {
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws ToolExecutionException
     */
    public function expandShortForm(string $table, array $fields): array
    {
        foreach ($fields as $fieldName => $value) {
            if ($this->isShortForm($table, (string)$fieldName, $value)) {
                $fields[$fieldName] = [self::DATA_KEY => $this->expandSheets((string)$fieldName, $value)];
            }
        }
        return $fields;
    }

    private function isShortForm(string $table, string $fieldName, mixed $value): bool
    {
        return is_array($value)
            && array_key_exists(self::DATA_KEY, $value) === false
            && $this->tcaService->isFlexFormField($table, $fieldName);
    }

    /**
     * @param array<mixed> $sheets
     * @return array<string, array<string, array<string, array<string, mixed>>>>
     * @throws ToolExecutionException
     */
    private function expandSheets(string $fieldName, array $sheets): array
    {
        $data = [];
        foreach ($sheets as $sheetName => $values) {
            if (is_array($values) === false || array_is_list($values) || $this->hasOnlyScalars($values) === false) {
                throw new ToolExecutionException(
                    'The FlexForm field "' . $fieldName . '" expects sheets with their fields, for example {"sDEF":'
                    . ' {"settings.limit": 5}}, or the complete structure with "data". Nothing was changed.',
                    1764081300
                );
            }
            $data[(string)$sheetName][self::LANGUAGE_KEY] = array_map(
                static fn (mixed $value): array => [self::VALUE_KEY => $value],
                $values
            );
        }
        return $data;
    }

    /**
     * @param array<mixed> $values
     */
    private function hasOnlyScalars(array $values): bool
    {
        return array_filter($values, static fn (mixed $value): bool => is_scalar($value)) === $values;
    }
}
