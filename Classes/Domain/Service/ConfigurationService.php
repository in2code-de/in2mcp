<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Access to the extension configuration of in2mcp
 */
readonly class ConfigurationService
{
    public const EXTENSION_KEY = 'in2mcp';

    public function __construct(private ExtensionConfiguration $extensionConfiguration)
    {
    }

    private const DEFAULT_FILE_IMPORT_MAXIMUM_SIZE = 10485760;

    /**
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function isMcpServerActivated(): bool
    {
        return (bool)$this->extensionConfiguration->get(self::EXTENSION_KEY, 'mcpServer');
    }

    /**
     * Importing a file by url makes the server perform an outgoing request that a client decides on, so it is
     * off unless an installation switches it on deliberately.
     */
    public function isFileImportActivated(): bool
    {
        return (bool)$this->get('fileImport');
    }

    public function getFileImportMaximumSize(): int
    {
        $maximumSize = (int)$this->get('fileImportMaximumSize');
        return $maximumSize > 0 ? $maximumSize : self::DEFAULT_FILE_IMPORT_MAXIMUM_SIZE;
    }

    /**
     * @return string[] Lowercase host names, an empty list allows every public host
     */
    public function getFileImportAllowedHosts(): array
    {
        $hosts = array_filter(array_map(
            static fn(string $host): string => strtolower(trim($host)),
            explode(',', (string)$this->get('fileImportAllowedHosts'))
        ));

        return array_values($hosts);
    }

    /**
     * A setting that an installation has never saved does not exist in the configuration yet, which is not an
     * error here - the default of the tool applies then.
     */
    private function get(string $path): mixed
    {
        try {
            return $this->extensionConfiguration->get(self::EXTENSION_KEY, $path);
        } catch (
            ExtensionConfigurationExtensionNotConfiguredException |
            ExtensionConfigurationPathDoesNotExistException
        ) {
            return null;
        }
    }
}
