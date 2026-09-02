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

    /**
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function isMcpServerActivated(): bool
    {
        return (bool)$this->extensionConfiguration->get(self::EXTENSION_KEY, 'mcpServer');
    }
}
