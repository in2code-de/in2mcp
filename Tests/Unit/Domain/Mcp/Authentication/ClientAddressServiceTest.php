<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\Authentication;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ClientAddressServiceTest extends UnitTestCase
{
    public static function allowedClientDataProvider(): array
    {
        return [
            'no list allows everybody' => [[], '203.0.113.7', true],
            'asterisk allows everybody' => [['*'], '203.0.113.7', true],
            'asterisk allows an unknown address' => [['*'], '', true],
            'anthropic outbound range' => [['160.79.104.0/21', '153.61.208.0/20'], '160.79.110.12', true],
            'single address' => [['203.0.113.7'], '203.0.113.7', true],
            'other address' => [['203.0.113.7'], '203.0.113.8', false],
            'ipv4 range' => [['192.168.0.0/24'], '192.168.0.42', true],
            'outside ipv4 range' => [['192.168.0.0/24'], '192.168.1.42', false],
            'wildcard' => [['10.1.*.*'], '10.1.200.3', true],
            'ipv6 range' => [['2001:db8::/32'], '2001:db8:1:2::1', true],
            'outside ipv6 range' => [['2001:db8::/32'], '2001:db9::1', false],
            'second entry of the list' => [['10.0.0.1', '203.0.113.0/24'], '203.0.113.7', true],
            'unknown address with a list' => [['203.0.113.7'], '', false],
        ];
    }

    /**
     * @param list<string> $allowedIps
     */
    #[Test]
    #[DataProvider('allowedClientDataProvider')]
    public function isAllowedClientComparesTheAddressWithTheList(
        array $allowedIps,
        string $address,
        bool $expected
    ): void
    {
        $configurationService = $this->createStub(ConfigurationService::class);
        $configurationService->method('getMcpServerAllowedIps')->willReturn($allowedIps);
        $request = (new ServerRequest('https://example.org/typo3/mcp', 'POST'))->withAttribute(
            'normalizedParams',
            $this->createNormalizedParams($address)
        );

        self::assertSame($expected, (new ClientAddressService($configurationService))->isAllowedClient($request));
    }

    private function createNormalizedParams(string $address): NormalizedParams
    {
        $normalizedParams = $this->createStub(NormalizedParams::class);
        $normalizedParams->method('getRemoteAddress')->willReturn($address);
        return $normalizedParams;
    }
}
