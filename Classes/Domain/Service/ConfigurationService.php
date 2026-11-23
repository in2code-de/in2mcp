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
     * Path of the endpoint below the backend entry point, when nothing else is configured
     */
    public const DEFAULT_MCP_SERVER_PATH = 'mcp';

    private const DEFAULT_BACKEND_ENTRY_POINT = '/typo3';

    private const FILE_UPLOAD_PATH_SEGMENT = 'upload';
    private const AUTHORIZATION_PATH_SEGMENT = 'authorize';
    private const TOKEN_PATH_SEGMENT = 'token';
    private const REGISTRATION_PATH_SEGMENT = 'register';
    private const PROTECTED_RESOURCE_METADATA_PATH_SEGMENT = '.well-known/oauth-protected-resource';

    // RFC 8414 puts the metadata of an issuer with a path at the root of the host, in front of that path
    private const AUTHORIZATION_SERVER_METADATA_PATH = '/.well-known/oauth-authorization-server';

    /**
     * Everything a path segment may consist of. A path is not a place for anything else, and this is the only
     * value of the extension configuration that ends up in a route comparison. "." and ".." pass this pattern
     * and are refused separately - they are relative path steps, not a name.
     */
    private const ALLOWED_PATH_PATTERN = '#^[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#';

    private const REFUSED_PATH_SEGMENTS = ['.', '..'];

    /**
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function isMcpServerActivated(): bool
    {
        return (bool)$this->extensionConfiguration->get(self::EXTENSION_KEY, 'mcpServer');
    }

    /**
     * Absolute path the MCP server answers on, for example "/typo3/mcp".
     *
     * The configured value is a path **below the backend entry point**, never a free path. The middleware runs
     * in the backend stack, which only sees requests below that entry point at all - and TYPO3 decides by it
     * whether a request is a backend request, which is what makes the file mounts and file permissions of the
     * user apply. An endpoint outside of it would be either unreachable or, worse, reachable without those
     * checks.
     */
    public function getMcpServerPath(): string
    {
        return $this->getBackendEntryPoint() . '/' . $this->getMcpServerPathSegment();
    }

    public function getFileUploadPath(): string
    {
        return $this->getMcpServerPath() . '/' . self::FILE_UPLOAD_PATH_SEGMENT;
    }

    public function getAuthorizationPath(): string
    {
        return $this->getMcpServerPath() . '/' . self::AUTHORIZATION_PATH_SEGMENT;
    }

    public function getTokenPath(): string
    {
        return $this->getMcpServerPath() . '/' . self::TOKEN_PATH_SEGMENT;
    }

    public function getRegistrationPath(): string
    {
        return $this->getMcpServerPath() . '/' . self::REGISTRATION_PATH_SEGMENT;
    }

    public function getProtectedResourceMetadataPath(): string
    {
        return $this->getMcpServerPath() . '/' . self::PROTECTED_RESOURCE_METADATA_PATH_SEGMENT;
    }

    public function getAuthorizationServerMetadataPath(): string
    {
        return self::AUTHORIZATION_SERVER_METADATA_PATH . $this->getMcpServerPath();
    }

    /**
     * The configured path below the backend entry point. A value that is not a plain path segment falls back to
     * the default instead of turning into a route nobody intended.
     */
    public function getMcpServerPathSegment(): string
    {
        $path = trim((string)$this->get('mcpServerPath'));
        $path = trim($path, '/');

        // A pasted full path like "/typo3/mcp" is meant as "mcp" and is accepted as such
        $entryPoint = trim($this->getBackendEntryPoint(), '/');
        if ($entryPoint !== '' && str_starts_with($path, $entryPoint . '/')) {
            $path = substr($path, strlen($entryPoint) + 1);
        }

        if ($path === '' || preg_match(self::ALLOWED_PATH_PATTERN, $path) !== 1) {
            return self::DEFAULT_MCP_SERVER_PATH;
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, self::REFUSED_PATH_SEGMENTS, true)) {
                return self::DEFAULT_MCP_SERVER_PATH;
            }
        }

        return $path;
    }

    /**
     * Path of the TYPO3 backend, which an installation may have moved. An entry point configured as a full url
     * contributes only its path here.
     */
    private function getBackendEntryPoint(): string
    {
        $entryPoint = (string)($GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] ?? self::DEFAULT_BACKEND_ENTRY_POINT);

        if (str_contains($entryPoint, '://') || str_starts_with($entryPoint, '//')) {
            $entryPoint = (string)parse_url($entryPoint, PHP_URL_PATH);
        }

        $entryPoint = '/' . trim($entryPoint, '/');
        return $entryPoint === '/' ? '' : $entryPoint;
    }

    /**
     * Importing a file by url makes the server perform an outgoing request that a client decides on, so it is
     * off unless an installation switches it on deliberately.
     */
    public function isFileImportActivated(): bool
    {
        return (bool)$this->get('fileImport');
    }

    public function isOAuthActivated(): bool
    {
        return (bool)$this->get('oauth');
    }

    // Fetching the document of a client is an outgoing request, so it is off unless switched on deliberately
    public function isClientMetadataDocumentActivated(): bool
    {
        return (bool)$this->get('oauthClientMetadataDocuments');
    }

    public function isApiKeyAuthenticationActivated(): bool
    {
        return (bool)$this->get('apiKeyAuthentication');
    }

    public function isFileUploadActivated(): bool
    {
        return (bool)$this->get('fileUpload');
    }

    public function getFileImportMaximumSize(): int
    {
        $maximumSize = (int)$this->get('fileImportMaximumSize');
        return $maximumSize > 0 ? $maximumSize : self::DEFAULT_FILE_IMPORT_MAXIMUM_SIZE;
    }

    /**
     * @return string[] Lowercase host names, an empty list allows every public host
     */
    /**
     * Addresses and ranges (CIDR or wildcards, as GeneralUtility::cmpIP() reads them) that may use the MCP server.
     * An empty list allows every address.
     *
     * @return list<string>
     */
    public function getMcpServerAllowedIps(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $address): string => trim($address),
            explode(',', (string)$this->get('mcpServerAllowedIps'))
        )));
    }

    public function getFileImportAllowedHosts(): array
    {
        $hosts = array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
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
