<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\ToolExecutionException;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;

// The empty value follows the TCA type, because an empty string compared with an integer column fails on PostgreSQL
readonly class EmptyFieldService
{
    private const TEXT_TYPES = ['input', 'text', 'email', 'link', 'slug', 'color'];
    private const NUMBER_TYPES = ['number', 'check'];
    private const DATE_TIME_TYPE = 'datetime';
    private const EMPTY_TEXT = '';
    private const EMPTY_NUMBER = 0;

    public function __construct(
        private TcaService $tcaService,
    ) {
    }

    /**
     * @throws ToolExecutionException
     */
    public function getEmptyValue(string $table, string $fieldName): string|int
    {
        $field = $this->tcaService->getFieldDefinition($table, $fieldName);
        if ($field !== null && in_array($field->getType(), self::TEXT_TYPES, true)) {
            return self::EMPTY_TEXT;
        }
        if ($field !== null && $this->isNumberField($table, $field)) {
            return self::EMPTY_NUMBER;
        }
        throw new ToolExecutionException(
            'The field "' . $fieldName . '" of "' . $table . '" can not be searched for an empty value. A null'
            . ' filter works for text fields, numbers, checkboxes, dates and the file and inline fields that count'
            . ' their children.',
            1795478401
        );
    }

    private function isNumberField(string $table, FieldTypeInterface $field): bool
    {
        return in_array($field->getType(), self::NUMBER_TYPES, true)
            || $this->isTimestampField($field)
            || $this->tcaService->getInlineRelation($table, $field->getName()) !== null;
    }

    private function isTimestampField(FieldTypeInterface $field): bool
    {
        return $field->getType() === self::DATE_TIME_TYPE
            && ($field->getConfiguration()['dbType'] ?? '') === '';
    }
}
