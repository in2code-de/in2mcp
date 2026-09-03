<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\InlineFieldType;
use TYPO3\CMS\Core\Schema\Field\StaticSelectFieldType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Answers questions about the schema of the installation, so a client can find out which page types, content types
 * and fields actually exist here instead of guessing.
 *
 * Every answer comes from the Schema API and never from $GLOBALS['TCA']: a field there is an object that knows its
 * own type, its label and whether it is required, a table knows its capabilities, and a table that does not exist
 * in this installation is simply absent instead of an array key that has to be guarded on every single read.
 */
class TcaService
{
    /**
     * Fields that carry no information for a client and would only blow up a record
     */
    private const IRRELEVANT_FIELDS = [
        'crdate',
        'tstamp',
        'cruser_id',
        'perms_userid',
        'perms_groupid',
        'perms_user',
        'perms_group',
        'perms_everybody',
        't3ver_oid',
        't3ver_wsid',
        't3ver_state',
        't3ver_stage',
        'l10n_diffsource',
        'l10n_state',
        'l18n_diffsource',
        'rowDescription',
        'SYS_LASTCHANGED',
    ];

    private ?LanguageService $languageService = null;

    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly LanguageServiceFactory $languageServiceFactory
    ) {
    }

    /**
     * @return array<array{value: int|string, label: string}>
     */
    public function getPageTypes(): array
    {
        return $this->getItemsOfSelectField('pages', 'doktype');
    }

    /**
     * @return array<array{value: int|string, label: string}>
     */
    public function getContentTypes(): array
    {
        return $this->getItemsOfSelectField('tt_content', 'CType');
    }

    public function getPageTypeName(int $doktype): string
    {
        foreach ($this->getPageTypes() as $item) {
            if ((int)$item['value'] === $doktype) {
                return $item['label'];
            }
        }
        return 'Unknown page type ' . $doktype;
    }

    /**
     * All fields of a record type with their type and label, so a client knows what it may write
     *
     * @return array<string, array{type: string, label: string, required: bool}>
     */
    public function getFields(string $table): array
    {
        $fields = [];
        foreach ($this->getSchema($table)?->getFields() ?? [] as $field) {
            if (in_array($field->getName(), self::IRRELEVANT_FIELDS, true)) {
                continue;
            }
            $fields[$field->getName()] = [
                'type' => $field->getType(),
                'label' => $this->getFieldLabel($field),
                'required' => $field->isRequired(),
            ];
        }
        return $fields;
    }

    /**
     * Removes internal fields and empty values from a record. Numeric zero is kept, because it is a meaningful
     * value for flags like "hidden".
     */
    public function cleanUpRecord(string $table, array $record): array
    {
        $alwaysKeptFields = $this->getAlwaysKeptFields($table);
        $cleanedRecord = [];
        foreach ($record as $fieldName => $value) {
            $fieldName = (string)$fieldName;
            if (in_array($fieldName, self::IRRELEVANT_FIELDS, true)) {
                continue;
            }
            if (in_array($fieldName, $alwaysKeptFields, true) === false
                && $this->isFieldOfTable($table, $fieldName) === false) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $cleanedRecord[$fieldName] = $value;
        }
        return $cleanedRecord;
    }

    /**
     * Field that holds the manual sorting of a table, null if the table is not sortable. Records of a sortable
     * table have to be created behind their last sibling to end up at the end instead of at the beginning.
     */
    public function getSortingField(string $table): ?string
    {
        $schema = $this->getSchema($table);
        if ($schema === null || $schema->hasCapability(TcaSchemaCapability::SortByField) === false) {
            return null;
        }
        return $schema->getCapability(TcaSchemaCapability::SortByField)->getFieldName();
    }

    /**
     * Title of a table as it is shown in the backend
     */
    public function getTableLabel(string $table): string
    {
        $schema = $this->getSchema($table);
        $title = $schema?->getTitle(fn(string $label): string => $this->translate($label)) ?? '';
        return $title === '' ? $table : $title;
    }

    /**
     * Fields that are no field of the schema but still belong to a record a client works with. The sorting is one
     * of them: it is not editable, but without it a client cannot tell in which order records actually are.
     *
     * @return string[]
     */
    public function getAlwaysKeptFields(string $table): array
    {
        $fields = ['uid', 'pid'];
        $sortingField = $this->getSortingField($table);
        if ($sortingField !== null) {
            $fields[] = $sortingField;
        }
        return $fields;
    }

    /**
     * Description of an inline relation of a field, null when the field is no inline relation.
     *
     * The database column of such a field holds the **number** of children, while the DataHandler expects the
     * **list of their uids** when it is written. Confusing the two attaches foreign records to the parent, so
     * every read resolves the field to the real list and every write is checked against it.
     *
     * @return array{foreignTable: string, foreignField: string, foreignSortby: string}|null
     */
    public function getInlineRelation(string $table, string $fieldName): ?array
    {
        $schema = $this->getSchema($table);
        if ($schema === null || $schema->hasField($fieldName) === false) {
            return null;
        }

        $field = $schema->getField($fieldName);
        if ($field instanceof InlineFieldType === false) {
            return null;
        }

        // The Schema API has no accessor for "foreign_sortby", so all three values are taken from the
        // configuration of the field object to keep them from two different sources apart
        $configuration = $field->getConfiguration();
        $foreignTable = (string)($configuration['foreign_table'] ?? '');
        $foreignField = (string)($configuration['foreign_field'] ?? '');
        if ($foreignTable === '' || $foreignField === '') {
            return null;
        }

        return [
            'foreignTable' => $foreignTable,
            'foreignField' => $foreignField,
            'foreignSortby' => (string)($configuration['foreign_sortby'] ?? ''),
        ];
    }

    /**
     * @return array<string, array{foreignTable: string, foreignField: string, foreignSortby: string}>
     */
    public function getInlineRelations(string $table): array
    {
        $relations = [];
        foreach ($this->getSchema($table)?->getFields() ?? [] as $field) {
            $relation = $this->getInlineRelation($table, $field->getName());
            if ($relation !== null) {
                $relations[$field->getName()] = $relation;
            }
        }
        return $relations;
    }

    /**
     * Field that holds the language of a record, null when the table is not translatable
     */
    public function getLanguageField(string $table): ?string
    {
        $schema = $this->getSchema($table);
        if ($schema === null || $schema->hasCapability(TcaSchemaCapability::Language) === false) {
            return null;
        }
        return $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName();
    }

    public function isSortingField(string $table, string $fieldName): bool
    {
        return $this->getSortingField($table) === $fieldName;
    }

    public function isFieldOfTable(string $table, string $fieldName): bool
    {
        return $this->getSchema($table)?->hasField($fieldName) === true;
    }

    /**
     * Null when the table is not configured in this installation. Every method of this service answers such a
     * table with an empty result instead of throwing, because a client is free to ask for anything.
     */
    private function getSchema(string $table): ?TcaSchema
    {
        return $this->tcaSchemaFactory->has($table) ? $this->tcaSchemaFactory->get($table) : null;
    }

    /**
     * @return array<array{value: int|string, label: string}>
     */
    private function getItemsOfSelectField(string $table, string $fieldName): array
    {
        $schema = $this->getSchema($table);
        if ($schema === null || $schema->hasField($fieldName) === false) {
            return [];
        }

        $field = $schema->getField($fieldName);
        if ($field instanceof StaticSelectFieldType === false) {
            return [];
        }

        $items = [];
        foreach ($field->getItems() as $item) {
            if ($item->getValue() === null || $item->isDivider()) {
                continue;
            }
            $items[] = [
                'value' => $item->getValue(),
                'label' => $this->translate($item->getLabel()),
            ];
        }
        return $items;
    }

    private function getFieldLabel(FieldTypeInterface $field): string
    {
        $label = $field->getLabel();
        return $label === '' ? $field->getName() : $this->translate($label);
    }

    /**
     * Labels come either as classic "LLL:EXT:..." reference or, since TYPO3 v14, as short domain reference like
     * "core.db.pages:doktype.default". Everything that is neither is a literal label already.
     */
    private function translate(string $label): string
    {
        if ($this->isLabelReference($label) === false) {
            return $label;
        }

        $this->languageService ??= $this->languageServiceFactory
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        $translation = $this->languageService->sL($label);
        return $translation === '' ? $label : $translation;
    }

    private function isLabelReference(string $label): bool
    {
        if (str_starts_with($label, 'LLL:')) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9_.\\-]+:[A-Za-z0-9_.\\-]+$/', $label) === 1;
    }
}
