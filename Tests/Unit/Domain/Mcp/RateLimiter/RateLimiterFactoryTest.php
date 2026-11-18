<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\RateLimiter;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Mcp\RateLimiter\RateLimiterFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RateLimiterFactoryTest extends UnitTestCase
{
    public static function limitedAddressDataProvider(): array
    {
        return [
            'ipv4 stays as it is' => ['203.0.113.7', '203.0.113.7'],
            'ipv6 counts per /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'],
            'another address of the same /64' => ['2001:db8:1:2::1', '2001:db8:1:2::/64'],
            'ipv4-mapped counts per ipv4 address' => ['::ffff:203.0.113.7', '203.0.113.7'],
            'no address at all' => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('limitedAddressDataProvider')]
    public function getLimitedAddressGroupsAddressesOfOneClient(string $remoteAddress, string $expected): void
    {
        self::assertSame($expected, $this->getLimitedAddress($remoteAddress));
    }

    #[Test]
    public function addressesOfDifferentNetworksAreCountedApart(): void
    {
        self::assertNotSame(
            $this->getLimitedAddress('2001:db8:1:2::1'),
            $this->getLimitedAddress('2001:db8:1:3::1')
        );
    }

    private function getLimitedAddress(string $remoteAddress): string
    {
        $subject = new class (
            $this->createStub(CachingFrameworkStorage::class),
            $this->createStub(LockFactory::class),
            $this->createStub(ClientAddressService::class)
        ) extends RateLimiterFactory {
            public function getLimitedAddressForTest(string $remoteAddress): string
            {
                return $this->getLimitedAddress($remoteAddress);
            }
        };
        return $subject->getLimitedAddressForTest($remoteAddress);
    }
}
