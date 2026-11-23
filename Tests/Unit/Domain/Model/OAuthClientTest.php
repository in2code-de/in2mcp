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
    public function fromRowReadsOneRedirectUriPerLine(): void
    {
        $subject = OAuthClient::fromRow([
            'client_id' => 'abc',
            'client_name' => 'Gemini',
            'redirect_uris' => "https://a.example/cb\r\n\n  https://b.example/cb  \n",
            'registration_type' => OAuthClient::TYPE_MANUAL,
            'client_secret_hash' => '$argon2i$hash',
        ]);

        self::assertSame(['https://a.example/cb', 'https://b.example/cb'], $subject->getRedirectUris());
        self::assertSame('https://a.example/cb' . PHP_EOL . 'https://b.example/cb', $subject->getRedirectUrisAsText());
        self::assertTrue($subject->hasSecret());
    }

    public static function pkceOptionalDataProvider(): array
    {
        return [
            'manual client with secret' => [OAuthClient::TYPE_MANUAL, 'hash', true],
            'manual client without secret' => [OAuthClient::TYPE_MANUAL, '', false],
            'registered client with secret' => [OAuthClient::TYPE_DYNAMIC, 'hash', false],
            'metadata document client' => [OAuthClient::TYPE_METADATA_DOCUMENT, '', false],
        ];
    }

    #[Test]
    #[DataProvider('pkceOptionalDataProvider')]
    public function isPkceOptionalOnlyForAManualClientWithSecret(
        string $registrationType,
        string $secretHash,
        bool $expected
    ): void {
        $subject = new OAuthClient('abc', 'Client', ['https://a.example/cb'], $registrationType, $secretHash);

        self::assertSame($expected, $subject->isPkceOptional());
    }

    #[Test]
    public function cleanNameRemovesControlCharactersAndCutsTheName(): void
    {
        self::assertSame('Claude Code', OAuthClient::cleanName("Claude\u{0000}Code"));
        self::assertSame(100, mb_strlen(OAuthClient::cleanName(str_repeat('ä', 150))));
        self::assertSame('', OAuthClient::cleanName(['no string']));
    }
}
