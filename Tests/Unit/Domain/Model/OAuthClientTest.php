<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Model;

use In2code\In2mcp\Domain\Model\OAuthClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class OAuthClientTest extends UnitTestCase
{
    public static function redirectUriDataProvider(): array
    {
        return [
            'registered uri' => ['https://claude.ai/api/mcp/auth_callback', true],
            'other path' => ['https://claude.ai/api/mcp/other', false],
            'additional query' => ['https://claude.ai/api/mcp/auth_callback?x=1', false],
            'other host' => ['https://evil.example.org/api/mcp/auth_callback', false],
            'loopback with registered port' => ['http://localhost:33418/callback', true],
            'loopback with other port' => ['http://localhost:51234/callback', true],
            'loopback without port' => ['http://localhost/callback', true],
            'loopback with other path' => ['http://localhost:51234/other', false],
            'other loopback host' => ['http://127.0.0.1:51234/callback', false],
            'ipv6 loopback with other port' => ['http://[::1]:4000/cb', true],
            'https port is not ignored' => ['https://claude.ai:8443/api/mcp/auth_callback', false],
        ];
    }

    #[Test]
    #[DataProvider('redirectUriDataProvider')]
    public function isRedirectUriAllowedIgnoresThePortOfALoopbackUriOnly(string $redirectUri, bool $expected): void
    {
        $subject = new OAuthClient(
            'client',
            'Client',
            ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:33418/callback', 'http://[::1]:3000/cb'],
            OAuthClient::TYPE_DYNAMIC
        );

        self::assertSame($expected, $subject->isRedirectUriAllowed($redirectUri));
    }

    #[Test]
    public function fromRowReadsTheRedirectUrisFromJson(): void
    {
        $subject = OAuthClient::fromRow([
            'client_id' => 'abc',
            'client_name' => 'Claude',
            'redirect_uris' => '["https://claude.ai/cb",4]',
            'registration_type' => 'dcr',
        ]);

        self::assertSame(['https://claude.ai/cb'], $subject->getRedirectUris());
        self::assertFalse($subject->hasSecret());
    }

    #[Test]
    public function fromRowSurvivesBrokenJson(): void
    {
        self::assertSame([], OAuthClient::fromRow(['redirect_uris' => '{broken'])->getRedirectUris());
    }
}
