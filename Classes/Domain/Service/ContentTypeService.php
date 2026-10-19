<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Answers which content element types an editor can actually choose on a page.
 *
 * The TCA lists every type that is registered in the installation, but an integrator narrows that list per page
 * with "TCEFORM.tt_content.CType" in the page TSconfig - often drastically, for example by removing the core types
 * "text" or "header" in favour of a single "textmedia". The backend form applies this configuration, the
 * DataHandler does not. A client that only knows the TCA list therefore creates elements an editor could never
 * have created, and TYPO3 accepts them without a word.
 *
 * The configuration is applied in the same order as the form engine does: "addItems" first, then "keepItems",
 * then "removeItems".
 */
class ContentTypeService
{
    private const CONTENT_TABLE = 'tt_content';
    private const CONTENT_TYPE_FIELD = 'CType';

    public function __construct(
        private readonly TcaService $tcaService,
    ) {
    }

    /**
     * Content types of the installation, restricted by the page TSconfig of the given page. Without a page the
     * unrestricted list of the TCA is returned, because there is no page whose configuration could apply.
     *
     * @return array<array{value: int|string, label: string}>
     */
    public function getContentTypes(int $pageUid): array
    {
        $contentTypes = $this->tcaService->getContentTypes();
        if ($pageUid <= 0) {
            return $contentTypes;
        }

        $configuration = $this->getContentTypeConfiguration($pageUid);
        $contentTypes = $this->addItems($contentTypes, $configuration['addItems.'] ?? []);
        $contentTypes = $this->keepItems($contentTypes, (string)($configuration['keepItems'] ?? ''));
        return $this->removeItems($contentTypes, (string)($configuration['removeItems'] ?? ''));
    }

    /**
     * Configuration of "TCEFORM.tt_content.CType" for a page, with the trailing dots of TypoScript keys
     */
    private function getContentTypeConfiguration(int $pageUid): array
    {
        $pageTsConfig = BackendUtility::getPagesTSconfig($pageUid);
        return $pageTsConfig['TCEFORM.'][self::CONTENT_TABLE . '.'][self::CONTENT_TYPE_FIELD . '.'] ?? [];
    }

    /**
     * @param array<array{value: int|string, label: string}> $contentTypes
     * @param array<string, string> $addItems Value of the new type to its label
     * @return array<array{value: int|string, label: string}>
     */
    private function addItems(array $contentTypes, array $addItems): array
    {
        foreach ($addItems as $value => $label) {
            if (is_string($label) === false || in_array((string)$value, $this->getValues($contentTypes), true)) {
                continue;
            }
            $contentTypes[] = [
                'value' => (string)$value,
                'label' => $this->tcaService->translateLabel($label),
            ];
        }
        return $contentTypes;
    }

    /**
     * @param array<array{value: int|string, label: string}> $contentTypes
     * @return array<array{value: int|string, label: string}>
     */
    private function keepItems(array $contentTypes, string $keepItems): array
    {
        if (trim($keepItems) === '') {
            return $contentTypes;
        }

        $keptValues = GeneralUtility::trimExplode(',', $keepItems, true);
        return array_values(array_filter(
            $contentTypes,
            static fn(array $contentType): bool => in_array((string)$contentType['value'], $keptValues, true)
        ));
    }

    /**
     * @param array<array{value: int|string, label: string}> $contentTypes
     * @return array<array{value: int|string, label: string}>
     */
    private function removeItems(array $contentTypes, string $removeItems): array
    {
        $removedValues = GeneralUtility::trimExplode(',', $removeItems, true);
        return array_values(array_filter(
            $contentTypes,
            static fn(array $contentType): bool => in_array((string)$contentType['value'], $removedValues, true)
                === false
        ));
    }

    /**
     * @param array<array{value: int|string, label: string}> $contentTypes
     * @return string[]
     */
    private function getValues(array $contentTypes): array
    {
        return array_map(static fn(array $contentType): string => (string)$contentType['value'], $contentTypes);
    }
}
