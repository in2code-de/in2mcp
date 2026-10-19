<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
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
        private readonly BackendUserService $backendUserService,
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
     * TYPO3 itself does not check this: the backend form hides such types, the DataHandler writes them anyway.
     * Refusing them here keeps a client from creating elements no editor of this page could have created.
     *
     * @throws ToolExecutionException
     */
    public function assertAvailableContentType(string $contentType, int $pageUid): void
    {
        $contentTypeValues = $this->getValues($this->getContentTypes($pageUid));
        if (in_array($contentType, $contentTypeValues, true)) {
            return;
        }

        throw new ToolExecutionException(
            'The content type "' . $contentType . '" is not available on page ' . $pageUid . '. Available types:'
            . ' ' . implode(', ', $contentTypeValues) . '. Call "get_schema" with "pageUid" ' . $pageUid
            . ' to see their labels and the default type of this page.',
            1756802004
        );
    }

    /**
     * The content type a new element gets in the backend when the editor chooses nothing else - "TCAdefaults" of
     * the page TSconfig, then of the user TSconfig, then the default of the TCA, which is the order in which the
     * backend form initializes a new record. A default that is not available on the page is replaced by the first
     * type that is, because the backend form would preselect exactly that one.
     *
     * @throws UserNotFoundException
     */
    public function getDefaultContentType(int $pageUid): string
    {
        $contentTypeValues = $this->getValues($this->getContentTypes($pageUid));

        $defaultContentType = $this->getConfiguredDefault($this->getPageTsConfig($pageUid))
            ?? $this->getConfiguredDefault($this->backendUserService->getBackendUser()->getTSConfig())
            ?? $this->tcaService->getDefaultValue(self::CONTENT_TABLE, self::CONTENT_TYPE_FIELD);

        if (in_array($defaultContentType, $contentTypeValues, true)) {
            return $defaultContentType;
        }
        return $contentTypeValues[0] ?? '';
    }

    /**
     * Value of "TCAdefaults.tt_content.CType" in a page or user TSconfig, null when it is not set
     */
    private function getConfiguredDefault(array $tsConfig): ?string
    {
        $default = $tsConfig['TCAdefaults.'][self::CONTENT_TABLE . '.'][self::CONTENT_TYPE_FIELD] ?? null;
        return is_scalar($default) && (string)$default !== '' ? (string)$default : null;
    }

    private function getPageTsConfig(int $pageUid): array
    {
        return $pageUid > 0 ? BackendUtility::getPagesTSconfig($pageUid) : [];
    }

    /**
     * Configuration of "TCEFORM.tt_content.CType" for a page, with the trailing dots of TypoScript keys
     */
    private function getContentTypeConfiguration(int $pageUid): array
    {
        $pageTsConfig = $this->getPageTsConfig($pageUid);
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
