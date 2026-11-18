<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Exception\ToolExecutionException;

/**
 * Fields of a translation the DataHandler does not write: a field with l10n_mode "exclude" always has the value of
 * the default language, and a field with "allowLanguageSynchronization" takes it until its localization state says
 * "custom". The DataHandler drops such values silently and still reports success, so they are refused here.
 *
 * The localization state can be changed with the field "l10n_state", exactly like the checkbox of the backend form
 * does it: {"l10n_state": {"author": "custom"}} together with the new value.
 */
readonly class TranslationFieldService
{
    public const STATE_FIELD = RecordRepository::LOCALIZATION_STATE_FIELD;

    private const STATE_CUSTOM = 'custom';
    private const STATE_PARENT = 'parent';
    private const VALID_STATES = ['custom', 'parent', 'source'];

    public function __construct(
        private TcaService $tcaService,
        private RecordRepository $recordRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function withoutStateField(array $fields): array
    {
        unset($fields[self::STATE_FIELD]);
        return $fields;
    }

    /**
     * Cheap for everything but a translation: the record is only read when a written field is excluded or
     * synchronized in translations at all
     *
     * @param array<string, mixed> $fields
     * @throws Exception
     * @throws ToolExecutionException
     */
    public function assertWritable(string $table, int $uid, array $fields): void
    {
        $requestedStates = $this->getRequestedStates($table, $fields);
        $fieldNames = array_map('strval', array_keys($this->withoutStateField($fields)));
        $excludedFields = array_values(array_filter(
            $fieldNames,
            fn(string $fieldName): bool => $this->tcaService->isExcludedFromTranslation($table, $fieldName)
        ));
        $synchronizedFields = array_values(array_filter(
            $fieldNames,
            fn(string $fieldName): bool => $this->tcaService->isSynchronizedInTranslation($table, $fieldName)
        ));

        $translationParentField = $this->tcaService->getTranslationParentField($table);
        $translation = $translationParentField !== null && ($excludedFields !== [] || $synchronizedFields !== [])
            ? $this->recordRepository->findTranslationState($table, $uid, $translationParentField)
            : null;

        if ($translation !== null && $translation['translationParent'] > 0) {
            $this->assertNoExcludedFields($excludedFields, $translation['translationParent']);
            $this->assertCustomState(
                $synchronizedFields,
                array_replace($translation['localizationState'], $requestedStates)
            );
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string>
     * @throws ToolExecutionException
     */
    private function getRequestedStates(string $table, array $fields): array
    {
        $states = $fields[self::STATE_FIELD] ?? [];
        $isValid = is_array($states);
        foreach (is_array($states) ? $states : [] as $fieldName => $state) {
            $isValid = $isValid
                && in_array($state, self::VALID_STATES, true)
                && $this->tcaService->isSynchronizedInTranslation($table, (string)$fieldName);
        }

        if ($isValid === false) {
            throw new ToolExecutionException(
                '"' . self::STATE_FIELD . '" has to be an object of field names and one of the states "'
                . implode('", "', self::VALID_STATES) . '", for example {"author": "custom"}. Only fields that'
                . ' are synchronized with the default language have a state. Nothing was written.',
                1756802700
            );
        }
        return $states;
    }

    /**
     * @param list<string> $excludedFields
     * @throws ToolExecutionException
     */
    private function assertNoExcludedFields(array $excludedFields, int $translationParent): void
    {
        if ($excludedFields !== []) {
            throw new ToolExecutionException(
                'The fields ' . implode(', ', $excludedFields) . ' of a translation always have the value of the'
                . ' record in the default language (uid ' . $translationParent . '). Change them there. Nothing was'
                . ' written.',
                1756802704
            );
        }
    }

    /**
     * @param list<string> $synchronizedFields
     * @param array<string, string> $states
     * @throws ToolExecutionException
     */
    private function assertCustomState(array $synchronizedFields, array $states): void
    {
        $lockedFields = array_values(array_filter(
            $synchronizedFields,
            static fn(string $fieldName): bool => ($states[$fieldName] ?? self::STATE_PARENT) !== self::STATE_CUSTOM
        ));

        if ($lockedFields !== []) {
            $example = array_fill_keys($lockedFields, self::STATE_CUSTOM);
            throw new ToolExecutionException(
                'The fields ' . implode(', ', $lockedFields) . ' of this translation take their value from the'
                . ' default language, so TYPO3 would ignore a new value. To give them an own value, pass "'
                . self::STATE_FIELD . '": ' . json_encode($example) . ' as a field together with the value'
                . ' (update_record, update_page, update_content_element), or alone before adding a file or a child'
                . ' record. Nothing was written.',
                1756802708
            );
        }
    }
}
