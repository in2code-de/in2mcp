<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Model;

use In2code\In2mcp\Domain\Model\ValidatedUrl;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ValidatedUrlTest extends UnitTestCase
{
    #[Test]
    public function curlResolveEntryPinsTheHostToTheAddress(): void
    {
        $validatedUrl = new ValidatedUrl('https://cdn.example.org/a.jpg', 'cdn.example.org', 443, '93.184.215.14');

        self::assertSame('cdn.example.org:443:93.184.215.14', $validatedUrl->getCurlResolveEntry());
    }

    #[Test]
    public function curlResolveEntryPutsIpv6AddressesInBrackets(): void
    {
        $validatedUrl = new ValidatedUrl('http://cdn.example.org/a.jpg', 'cdn.example.org', 80, '2606:4700::1111');

        self::assertSame('cdn.example.org:80:[2606:4700::1111]', $validatedUrl->getCurlResolveEntry());
    }

    #[Test]
    public function curlResolveEntryIsNullForAnIpAddressAsHost(): void
    {
        $validatedUrl = new ValidatedUrl('https://[2606:4700::1111]/a.jpg', '2606:4700::1111', 443, '2606:4700::1111');

        self::assertNull($validatedUrl->getCurlResolveEntry());
    }
}
