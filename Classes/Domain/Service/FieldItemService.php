<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\DataHandling\ItemProcessingService;
use TYPO3\CMS\Core\Schema\Field\CheckboxFieldType;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\RadioFieldType;
use TYPO3\CMS\Core\Schema\Field\StaticSelectFieldType;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Answers which values an editor can choose in a select, radio or multi checkbox field - the values a client has
 * to know to write such a field, for example the image position "imageorient" of a content element.
 *
 * The values are built the way the form engine builds them: the items of the TCA (with the "columnsOverrides" of
 * the record type), the "itemsProcFunc" of the field, then "keepItems", "addItems", "removeItems" and "altLabels"
 * of "TCEFORM" in the page TSconfig - including the part for the record type - and finally the "authMode" of the
 * backend user. Values from a foreign table are not listed, they are records and no fixed choice.
 */
class FieldItemService
{
    private const DIVIDER_VALUE = '--div--';
    private const MINIMUM_CHECKBOX_ITEMS = 2;
    private const KEEP_ITEMS_KEY = 'keepItems';
    private const ADD_ITEMS_KEY = 'addItems.';
    private const REMOVE_ITEMS_KEY = 'removeItems';
    private const ALT_LABELS_KEY = 'altLabels.';
    private const SUB_PROPERTY_SUFFIX = '.';

    public function __construct(
        private readonly TcaService $tcaService,
        private readonly TceFormService $tceFormService,
        private readonly BackendUserService $backendUserService,
        private readonly ItemProcessingService $itemProcessingService,
    ) {
    }

    /**
     * Adds the choosable values as "items" to the field definitions of TcaService::getFields() and marks fields
     * that are disabled by "TCEFORM.<table>.<field>.disabled" on the page.
     *
     * @param array<string, array> $fields
     * @param string[] $fieldsWithoutItems Fields whose values are reported elsewhere, for example "CType"
     * @return array<string, array>
     * @throws UserNotFoundException
     */
    public function addItemsToFields(
        string $table,
        array $fields,
        int $pageUid,
        string $recordType,
        array $fieldsWithoutItems = []
    ): array {
        foreach (array_keys($fields) as $fieldName) {
            $fieldName = (string)$fieldName;
            if ($this->tceFormService->isFieldDisabled($table, $fieldName, $pageUid, $recordType)) {
                $fields[$fieldName]['disabled'] = true;
            } elseif (in_array($fieldName, $fieldsWithoutItems, true) === false) {
                $items = $this->getItems($table, $fieldName, $pageUid, $recordType);
                if ($items !== null) {
                    $fields[$fieldName]['items'] = $items;
                }
            }
        }
        return $fields;
    }

    /**
     * Values of a select, radio or multi checkbox field. Null for every other field, because it offers no fixed
     * choice. The values of a multi checkbox are the bits of the bitmask that is stored in the field.
     *
     * @return array<array{value: int|string, label: string}>|null
     * @throws UserNotFoundException
     */
    public function getItems(string $table, string $fieldName, int $pageUid, string $recordType): ?array
    {
        $items = null;
        $field = $this->tcaService->getFieldDefinition($table, $fieldName, $recordType);
        if ($field instanceof StaticSelectFieldType) {
            $items = $this->getSelectItems($table, $field, $pageUid, $recordType);
        } elseif ($field instanceof RadioFieldType) {
            $items = $this->getRadioItems($table, $field, $pageUid, $recordType);
        } elseif ($field instanceof CheckboxFieldType
            && count($field->getConfiguration()['items'] ?? []) >= self::MINIMUM_CHECKBOX_ITEMS) {
            $items = $this->getCheckboxItems($table, $field, $pageUid, $recordType);
        }
        return $items;
    }

    /**
     * Applies "keepItems", "addItems", "removeItems" and "altLabels" of a "TCEFORM" configuration to the items of
     * a select field, in the order of the form engine - so an added item survives "keepItems", but not
     * "removeItems". An empty "keepItems" removes every item, exactly as in the backend form.
     *
     * @param array<array{value: int|string, label: string}> $items Items with translated labels
     * @param array $configuration Configuration of "TCEFORM.<table>.<field>" with the trailing dots of TypoScript
     * @return array<array{value: int|string, label: string}>
     */
    public function applyPageTsConfig(array $items, array $configuration): array
    {
        $keepItems = $configuration[self::KEEP_ITEMS_KEY] ?? null;
        if (is_string($keepItems)) {
            $keptValues = GeneralUtility::trimExplode(',', $keepItems, true);
            $items = array_filter(
                $items,
                static fn(array $item): bool => in_array((string)$item['value'], $keptValues, true)
            );
        }

        $items = $this->addItems($items, $configuration[self::ADD_ITEMS_KEY] ?? []);

        $removedValues = GeneralUtility::trimExplode(',', (string)($configuration[self::REMOVE_ITEMS_KEY] ?? ''), true);
        $items = array_filter(
            $items,
            static fn(array $item): bool => in_array((string)$item['value'], $removedValues, true) === false
        );

        $altLabels = $configuration[self::ALT_LABELS_KEY] ?? [];
        foreach ($items as $key => $item) {
            $altLabel = is_array($altLabels) ? ($altLabels[(string)$item['value']] ?? '') : '';
            if (is_string($altLabel) && $altLabel !== '') {
                $items[$key]['label'] = $this->tcaService->translateLabel($altLabel);
            }
        }

        return array_values($items);
    }

    /**
     * @return array<array{value: int|string, label: string}>
     * @throws UserNotFoundException
     */
    private function getSelectItems(
        string $table,
        StaticSelectFieldType $field,
        int $pageUid,
        string $recordType
    ): array {
        $items = $this->processItems($table, $field, $pageUid, $recordType);
        $items = $this->applyPageTsConfig(
            $items,
            $this->tceFormService->getFieldConfiguration($table, $field->getName(), $pageUid, $recordType)
        );
        return $this->removeItemsByAuthMode($table, $field, $items);
    }

    /**
     * Radio buttons know no "removeItems" or "addItems" in the form engine, only "altLabels" - keyed by the
     * position of the item instead of its value
     *
     * @return array<array{value: int|string, label: string}>
     */
    private function getRadioItems(string $table, RadioFieldType $field, int $pageUid, string $recordType): array
    {
        $items = $this->processItems($table, $field, $pageUid, $recordType);
        return $this->applyAltLabelsByPosition($items, $table, $field, $pageUid, $recordType);
    }

    /**
     * Every item of a multi checkbox is one bit of the value that is stored, so the value of an item is its bit:
     * 1 for the first item, 2 for the second, 4 for the third. Values are combined by adding them up.
     *
     * @return array<array{value: int|string, label: string}>
     */
    private function getCheckboxItems(
        string $table,
        CheckboxFieldType $field,
        int $pageUid,
        string $recordType
    ): array {
        $items = [];
        $position = 0;
        foreach ($field->getConfiguration()['items'] ?? [] as $item) {
            if (is_array($item)) {
                $items[] = [
                    'value' => 1 << $position,
                    'label' => $this->tcaService->translateLabel((string)($item['label'] ?? $item[0] ?? '')),
                ];
                $position++;
            }
        }
        return $this->applyAltLabelsByPosition($items, $table, $field, $pageUid, $recordType);
    }

    /**
     * Items of the TCA, completed by the "itemsProcFunc" of the field, with translated labels and without dividers
     *
     * @return array<array{value: int|string, label: string}>
     */
    private function processItems(string $table, FieldTypeInterface $field, int $pageUid, string $recordType): array
    {
        $configuration = $field->getConfiguration();
        $items = is_array($configuration['items'] ?? null) ? $configuration['items'] : [];

        if ((string)($configuration['itemsProcFunc'] ?? '') !== '') {
            try {
                $items = $this->itemProcessingService->getProcessingItems(
                    $table,
                    $pageUid,
                    $field->getName(),
                    $this->getRecordForItemsProcFunc($table, $pageUid, $recordType),
                    $configuration,
                    $items
                );
            } catch (\Throwable) {
                // An itemsProcFunc is written for the backend form and may rely on a request, a record or a
                // global that does not exist here. The items of the TCA are the best answer that is left then.
            }
        }

        return $this->normalizeItems($items);
    }

    /**
     * Items come as arrays with "label" and "value", as legacy arrays with numeric keys or - from an
     * itemsProcFunc - as SelectItem objects
     *
     * @return array<array{value: int|string, label: string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalizedItems = [];
        foreach ($items as $item) {
            $item = $item instanceof SelectItem ? $item->toArray() : $item;
            $value = is_array($item) ? ($item['value'] ?? $item[1] ?? null) : null;
            if (is_scalar($value) && (string)$value !== self::DIVIDER_VALUE) {
                $normalizedItems[] = [
                    'value' => is_int($value) ? $value : (string)$value,
                    'label' => $this->tcaService->translateLabel(trim((string)($item['label'] ?? $item[0] ?? ''))),
                ];
            }
        }
        return $normalizedItems;
    }

    /**
     * The record an itemsProcFunc gets to see: a new record on the page, of the record type that was asked for.
     * Many functions decide by the type which values they offer.
     */
    private function getRecordForItemsProcFunc(string $table, int $pageUid, string $recordType): array
    {
        $record = ['uid' => 0, 'pid' => $pageUid];
        $recordTypeField = $this->tcaService->getRecordTypeField($table);
        if ($recordTypeField !== null && $recordType !== '') {
            $record[$recordTypeField] = $recordType;
        }
        return $record;
    }

    /**
     * @param array<array{value: int|string, label: string}> $items
     * @param array $addItems "addItems." of the page TSconfig: value => label, plus "value." for icons and groups
     * @return array<array{value: int|string, label: string}>
     */
    private function addItems(array $items, mixed $addItems): array
    {
        $values = array_map(static fn(array $item): string => (string)$item['value'], $items);
        // A value from the TSconfig is always a string. It gets the type of the values of the TCA, so a field does
        // not mix 7 and "0" - integers only when the field has nothing but integers so far.
        $integerValues = array_filter($items, static fn(array $item): bool => is_int($item['value'])) === $items;
        foreach (is_array($addItems) ? $addItems : [] as $value => $label) {
            $value = (string)$value;
            if (is_string($label)
                && str_ends_with($value, self::SUB_PROPERTY_SUFFIX) === false
                && in_array($value, $values, true) === false
            ) {
                $items[] = [
                    'value' => $integerValues && MathUtility::canBeInterpretedAsInteger($value) ? (int)$value : $value,
                    'label' => $this->tcaService->translateLabel($label),
                ];
                $values[] = $value;
            }
        }
        return $items;
    }

    /**
     * @param array<array{value: int|string, label: string}> $items
     * @return array<array{value: int|string, label: string}>
     * @throws UserNotFoundException
     */
    private function removeItemsByAuthMode(string $table, FieldTypeInterface $field, array $items): array
    {
        if (is_string($field->getConfiguration()['authMode'] ?? null)) {
            $backendUser = $this->backendUserService->getBackendUser();
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => $backendUser->checkAuthMode($table, $field->getName(), $item['value'])
            ));
        }
        return $items;
    }

    /**
     * @param array<array{value: int|string, label: string}> $items
     * @return array<array{value: int|string, label: string}>
     */
    private function applyAltLabelsByPosition(
        array $items,
        string $table,
        FieldTypeInterface $field,
        int $pageUid,
        string $recordType
    ): array {
        $configuration = $this->tceFormService->getFieldConfiguration(
            $table,
            $field->getName(),
            $pageUid,
            $recordType
        );
        $altLabels = $configuration[self::ALT_LABELS_KEY] ?? [];
        foreach (is_array($altLabels) ? $altLabels : [] as $position => $altLabel) {
            if (isset($items[$position]) && is_string($altLabel) && $altLabel !== '') {
                $items[$position]['label'] = $this->tcaService->translateLabel($altLabel);
            }
        }
        return $items;
    }
}
