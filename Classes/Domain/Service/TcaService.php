<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Answers questions about the TCA of the installation, so a client can find out which page types, content types
 * and fields actually exist here instead of guessing.
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

    public function __construct(private readonly LanguageServiceFactory $languageServiceFactory)
    {
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
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $fieldName => $configuration) {
            if (in_array($fieldName, self::IRRELEVANT_FIELDS, true)) {
                continue;
            }
            $fields[$fieldName] = [
                'type' => (string)($configuration['config']['type'] ?? 'input'),
                'label' => $this->translate((string)($configuration['label'] ?? $fieldName)),
                'required' => (bool)($configuration['config']['required'] ?? false),
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
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $cleanedRecord = [];
        foreach ($record as $fieldName => $value) {
            if (in_array($fieldName, self::IRRELEVANT_FIELDS, true)) {
                continue;
            }
            if (in_array($fieldName, ['uid', 'pid'], true) === false
                && array_key_exists($fieldName, $columns) === false) {
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
        $sortingField = $GLOBALS['TCA'][$table]['ctrl']['sortby'] ?? null;
        return is_string($sortingField) && $sortingField !== '' ? $sortingField : null;
    }

    /**
     * Title of a table as it is shown in the backend
     */
    public function getTableLabel(string $table): string
    {
        return $this->translate((string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table));
    }

    public function isFieldOfTable(string $table, string $fieldName): bool
    {
        return array_key_exists($fieldName, $GLOBALS['TCA'][$table]['columns'] ?? []);
    }

    /**
     * @return array<array{value: int|string, label: string}>
     */
    private function getItemsOfSelectField(string $table, string $fieldName): array
    {
        $items = [];
        foreach ($GLOBALS['TCA'][$table]['columns'][$fieldName]['config']['items'] ?? [] as $item) {
            $value = $item['value'] ?? null;
            if ($value === null || $value === '--div--') {
                continue;
            }
            $items[] = [
                'value' => $value,
                'label' => $this->translate((string)($item['label'] ?? '')),
            ];
        }
        return $items;
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
