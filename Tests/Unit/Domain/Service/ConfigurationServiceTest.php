<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ConfigurationServiceTest extends UnitTestCase
{
    private const DEFAULT_CONFIGURATION = [
        'mcpServer' => '0',
        'mcpServerPath' => 'mcp',
        'fileImport' => '0',
        'fileImportMaximumSize' => '10485760',
        'fileImportAllowedHosts' => '',
    ];

    private ConfigurationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ConfigurationService(new ExtensionConfiguration());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ConfigurationService::EXTENSION_KEY]);
        unset($GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint']);
        parent::tearDown();
    }

    #[Test]
    public function isMcpServerActivatedReadsSwitch(): void
    {
        $this->configure(['mcpServer' => '0']);
        self::assertFalse($this->subject->isMcpServerActivated());

        $this->configure(['mcpServer' => '1']);
        self::assertTrue($this->subject->isMcpServerActivated());
    }

    public static function mcpServerPathSegmentDataProvider(): array
    {
        return [
            'default' => ['mcp', 'mcp'],
            'custom segment' => ['ai', 'ai'],
            'nested path' => ['api/mcp', 'api/mcp'],
            'surrounding slashes' => ['/api/mcp/', 'api/mcp'],
            'surrounding spaces' => ['  ai  ', 'ai'],
            'pasted full path' => ['/typo3/ai', 'ai'],
            'unreserved characters' => ['a.b_c~d-e', 'a.b_c~d-e'],
            'empty value' => ['', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'only slashes' => ['///', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'entry point itself' => ['/typo3', 'typo3'],
            'parent directory' => ['..', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'current directory' => ['.', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'parent directory inside path' => ['api/../mcp', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'query string' => ['mcp?debug=1', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'url' => ['https://example.org/mcp', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'empty segment' => ['api//mcp', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'space inside' => ['my mcp', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
            'regex characters' => ['mcp.*', ConfigurationService::DEFAULT_MCP_SERVER_PATH],
        ];
    }

    #[Test]
    #[DataProvider('mcpServerPathSegmentDataProvider')]
    public function getMcpServerPathSegmentReturnsSanitizedSegment(
        string $configuredPath,
        string $expectedSegment
    ): void {
        $this->configure(['mcpServerPath' => $configuredPath]);

        self::assertSame($expectedSegment, $this->subject->getMcpServerPathSegment());
    }

    public static function mcpServerPathDataProvider(): array
    {
        return [
            'default entry point' => [null, 'mcp', '/typo3/mcp'],
            'moved entry point' => ['/admin', 'mcp', '/admin/mcp'],
            'entry point without slash' => ['admin/', 'ai', '/admin/ai'],
            'entry point as url' => ['https://backend.example.org/admin', 'mcp', '/admin/mcp'],
            'entry point as protocol relative url' => ['//backend.example.org/admin', 'mcp', '/admin/mcp'],
            'entry point at root of url' => ['https://backend.example.org/', 'mcp', '/mcp'],
            'pasted full path of moved entry point' => ['/admin', '/admin/ai', '/admin/ai'],
        ];
    }

    #[Test]
    #[DataProvider('mcpServerPathDataProvider')]
    public function getMcpServerPathIsBelowBackendEntryPoint(
        ?string $entryPoint,
        string $configuredPath,
        string $expectedPath
    ): void {
        if ($entryPoint !== null) {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = $entryPoint;
        }
        $this->configure(['mcpServerPath' => $configuredPath]);

        self::assertSame($expectedPath, $this->subject->getMcpServerPath());
    }

    #[Test]
    public function isFileImportActivatedReadsSwitch(): void
    {
        $this->configure(['fileImport' => '1']);

        self::assertTrue($this->subject->isFileImportActivated());
    }

    public static function fileImportMaximumSizeDataProvider(): array
    {
        return [
            'configured size' => ['2048', 2048],
            'zero falls back' => ['0', 10485760],
            'negative falls back' => ['-1', 10485760],
            'empty falls back' => ['', 10485760],
            'no number falls back' => ['much', 10485760],
        ];
    }

    #[Test]
    #[DataProvider('fileImportMaximumSizeDataProvider')]
    public function getFileImportMaximumSizeFallsBackToDefault(string $configuredSize, int $expectedSize): void
    {
        $this->configure(['fileImportMaximumSize' => $configuredSize]);

        self::assertSame($expectedSize, $this->subject->getFileImportMaximumSize());
    }

    public static function fileImportAllowedHostsDataProvider(): array
    {
        return [
            'empty' => ['', []],
            'single host' => ['example.org', ['example.org']],
            'lowercased and trimmed' => [' Example.ORG , cdn.example.org ', ['example.org', 'cdn.example.org']],
            'empty entries removed' => [',example.org,,', ['example.org']],
        ];
    }

    #[Test]
    #[DataProvider('fileImportAllowedHostsDataProvider')]
    public function getFileImportAllowedHostsReturnsNormalizedList(string $configuredHosts, array $expectedHosts): void
    {
        $this->configure(['fileImportAllowedHosts' => $configuredHosts]);

        self::assertSame($expectedHosts, $this->subject->getFileImportAllowedHosts());
    }

    private function configure(array $configuration): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ConfigurationService::EXTENSION_KEY] = array_replace(
            self::DEFAULT_CONFIGURATION,
            $configuration
        );
    }
}
