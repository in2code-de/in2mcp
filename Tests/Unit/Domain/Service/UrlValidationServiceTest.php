<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\UrlValidationService;
use In2code\In2mcp\Exception\FileImportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Only ip literals are used, so no test depends on a working name resolution
 */
final class UrlValidationServiceTest extends UnitTestCase
{
    private UrlValidationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowHosts('');
        $this->subject = new UrlValidationService(new ConfigurationService(new ExtensionConfiguration()));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ConfigurationService::EXTENSION_KEY]);
        parent::tearDown();
    }

    public static function publicUrlDataProvider(): array
    {
        return [
            'http ipv4' => ['http://93.184.215.14/image.jpg'],
            'https ipv4 with port' => ['https://1.1.1.1:8443/image.jpg'],
            'uppercase scheme' => ['HTTPS://1.1.1.1/image.jpg'],
            'ipv6' => ['https://[2606:4700:4700::1111]/image.jpg'],
            'just outside carrier grade nat' => ['https://100.128.0.1/image.jpg'],
            'ipv4-mapped public address' => ['https://[::ffff:1.1.1.1]/image.jpg'],
            'nat64 of public address' => ['https://[64:ff9b::101:101]/image.jpg'],
            '6to4 of public address' => ['https://[2002:101:101::1]/image.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('publicUrlDataProvider')]
    #[DoesNotPerformAssertions]
    public function assertImportableAcceptsPublicAddress(string $url): void
    {
        $this->subject->assertImportable($url);
    }

    public static function invalidUrlDataProvider(): array
    {
        return [
            'no url' => ['image.jpg'],
            'file without host' => ['file:///etc/passwd'],
            'empty' => [''],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrlDataProvider')]
    public function assertImportableRefusesUrlWithoutHost(string $url): void
    {
        $this->expectException(FileImportException::class);
        $this->expectExceptionCode(1756801100);
        $this->subject->assertImportable($url);
    }

    public static function forbiddenSchemeDataProvider(): array
    {
        return [
            'ftp' => ['ftp://1.1.1.1/image.jpg'],
            'gopher' => ['gopher://1.1.1.1:70/_payload'],
            'php stream' => ['phar://1.1.1.1/archive.phar'],
            'no scheme' => ['//1.1.1.1/image.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('forbiddenSchemeDataProvider')]
    public function assertImportableRefusesOtherSchemes(string $url): void
    {
        $this->expectException(FileImportException::class);
        $this->expectExceptionCode(1756801104);
        $this->subject->assertImportable($url);
    }

    public static function internalAddressDataProvider(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/typo3/install.php'],
            'loopback range' => ['http://127.1.2.3/'],
            'unspecified' => ['http://0.0.0.0/'],
            'private 10/8' => ['http://10.0.0.1/'],
            'private 172.16/12' => ['http://172.16.5.4/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'link local cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'carrier grade nat start' => ['http://100.64.0.1/'],
            'carrier grade nat end' => ['http://100.127.255.255/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 unique local' => ['http://[fd00::1]/'],
            'ipv6 link local' => ['http://[fe80::1]/'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'ipv4-mapped loopback in hex' => ['http://[::ffff:7f00:1]/'],
            'ipv4-mapped private' => ['http://[::ffff:10.0.0.1]/'],
            'ipv4-mapped cloud metadata' => ['http://[::ffff:169.254.169.254]/latest/meta-data/'],
            'ipv4-mapped carrier grade nat' => ['http://[::ffff:100.64.0.1]/'],
            'ipv4-compatible loopback' => ['http://[::127.0.0.1]/'],
            '6to4 of loopback' => ['http://[2002:7f00:1::]/'],
            '6to4 of private' => ['http://[2002:c0a8:101::1]/'],
            'nat64 of loopback' => ['http://[64:ff9b::7f00:1]/'],
            'nat64 of cloud metadata' => ['http://[64:ff9b::a9fe:a9fe]/'],
            'nat64 for local use' => ['http://[64:ff9b:1::101:101]/'],
            'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/'],
        ];
    }

    #[Test]
    #[DataProvider('internalAddressDataProvider')]
    public function assertImportableRefusesInternalAddress(string $url): void
    {
        $this->expectException(FileImportException::class);
        $this->expectExceptionCode(1756801116);
        $this->subject->assertImportable($url);
    }

    #[Test]
    public function assertImportableRefusesHostOutsideOfAllowedHosts(): void
    {
        $this->allowHosts('cdn.example.org, 1.1.1.1');

        $this->expectException(FileImportException::class);
        $this->expectExceptionCode(1756801108);
        $this->subject->assertImportable('https://8.8.8.8/image.jpg');
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function assertImportableAcceptsHostOfAllowedHosts(): void
    {
        $this->allowHosts('cdn.example.org, 1.1.1.1');

        $this->subject->assertImportable('https://1.1.1.1/image.jpg');
    }

    #[Test]
    public function assertImportableRefusesInternalAddressEvenIfItIsAnAllowedHost(): void
    {
        $this->allowHosts('127.0.0.1');

        $this->expectException(FileImportException::class);
        $this->expectExceptionCode(1756801116);
        $this->subject->assertImportable('http://127.0.0.1/');
    }

    private function allowHosts(string $hosts): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ConfigurationService::EXTENSION_KEY] = [
            'fileImportAllowedHosts' => $hosts,
        ];
    }
}
