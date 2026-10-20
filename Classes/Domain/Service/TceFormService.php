<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Reads "TCEFORM" of the page TSconfig, the configuration an integrator uses to adjust the backend form of a field
 * per page: "removeItems", "addItems", "keepItems", "altLabels" and "disabled".
 *
 * The configuration of a field can be refined per record type with "TCEFORM.<table>.<field>.types.<type>", for
 * example to offer other image positions for one content type than for another. That part is merged over the
 * general configuration of the field, which is what the form engine does as well.
 */
class TceFormService
{
    private const TYPES_KEY = 'types.';
    private const DISABLED_KEY = 'disabled';

    /**
     * Complete page TSconfig of a page, an empty array without a page
     */
    public function getPageTsConfig(int $pageUid): array
    {
        $pageTsConfig = [];
        if ($pageUid > 0) {
            $pageTsConfig = BackendUtility::getPagesTSconfig($pageUid);
        }
        return $pageTsConfig;
    }

    /**
     * Configuration of "TCEFORM.<table>.<field>" for a page, with the configuration of the record type merged over
     * it. Keys keep the trailing dots of TypoScript.
     */
    public function getFieldConfiguration(string $table, string $fieldName, int $pageUid, string $recordType): array
    {
        $configuration = $this->getPageTsConfig($pageUid)['TCEFORM.'][$table . '.'][$fieldName . '.'] ?? [];
        $configuration = is_array($configuration) ? $configuration : [];

        $typeConfiguration = $configuration[self::TYPES_KEY][$recordType . '.'] ?? [];
        unset($configuration[self::TYPES_KEY]);
        if ($recordType !== '' && is_array($typeConfiguration)) {
            ArrayUtility::mergeRecursiveWithOverrule($configuration, $typeConfiguration);
        }

        return $configuration;
    }

    /**
     * A disabled field is not shown in the backend form, so an editor of this page can not set it
     */
    public function isFieldDisabled(string $table, string $fieldName, int $pageUid, string $recordType): bool
    {
        $configuration = $this->getFieldConfiguration($table, $fieldName, $pageUid, $recordType);
        return (bool)($configuration[self::DISABLED_KEY] ?? false);
    }
}
