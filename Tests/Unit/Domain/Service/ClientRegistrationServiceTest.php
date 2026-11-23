<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Domain\Service\ClientRegistrationService;
use In2code\In2mcp\Exception\OAuthException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ClientRegistrationServiceTest extends UnitTestCase
{
    private OAuthClientRepository&MockObject $clientRepository;
    private ClientRegistrationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientRepository = $this->createMock(OAuthClientRepository::class);
        $passwordHash = $this->createMock(PasswordHashInterface::class);
        $passwordHash->method('getHashedPassword')->willReturnCallback(
            static fn (string $secret): string => 'hashed:' . $secret
        );
        $passwordHashFactory = $this->createMock(PasswordHashFactory::class);
        $passwordHashFactory->method('getDefaultHashInstance')->willReturn($passwordHash);
        $this->subject = new ClientRegistrationService($this->clientRepository, new Random(), $passwordHashFactory);
    }

    public static function redirectUriDataProvider(): array
    {
        return [
            'https' => ['https://claude.ai/api/mcp/auth_callback', true],
            'https with query' => ['https://chatgpt.com/connector/oauth/abc?x=1', true],
            'localhost' => ['http://localhost:33418/callback', true],
            'ipv4 loopback' => ['http://127.0.0.1/callback', true],
            'ipv6 loopback' => ['http://[::1]:8080/callback', true],
            'http to a public host' => ['http://example.org/callback', false],
            'http to a name that starts with localhost' => ['http://localhost.example.org/cb', false],
            'fragment' => ['https://claude.ai/cb#x', false],
            'user info' => ['https://user@claude.ai/cb', false],
            'custom scheme' => ['cursor://anysphere.cursor-mcp/oauth/callback', false],
            'javascript' => ['javascript:alert(1)', false],
            'relative' => ['/callback', false],
            'space' => ['https://claude.ai/c b', false],
            'too long' => ['https://claude.ai/' . str_repeat('a', 2000), false],
        ];
    }

    #[Test]
    #[DataProvider('redirectUriDataProvider')]
    public function isAllowedRedirectUriAllowsHttpsAndLoopbackOnly(string $uri, bool $expected): void
    {
        self::assertSame($expected, $this->subject->isAllowedRedirectUri($uri));
    }

    #[Test]
    public function registerStoresAPublicClient(): void
    {
        $this->clientRepository->expects(self::once())->method('insert')->with(self::callback(
            static fn (OAuthClient $client): bool => $client->getRegistrationType() === OAuthClient::TYPE_DYNAMIC
                && $client->getClientName() === 'Claude'
                && $client->hasSecret() === false
        ));

        $registration = $this->subject->register([
            'client_name' => "Claude\u{0007}",
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $registration['client_id']);
        self::assertSame('none', $registration['token_endpoint_auth_method']);
        self::assertArrayNotHasKey('client_secret', $registration);
    }

    public static function secretAuthenticationMethodDataProvider(): array
    {
        return [
            'basic' => ['client_secret_basic'],
            'post' => ['client_secret_post'],
        ];
    }

    #[Test]
    #[DataProvider('secretAuthenticationMethodDataProvider')]
    public function registerIssuesASecretToAClientThatAsksForOne(string $method): void
    {
        $storedClient = null;
        $this->clientRepository->expects(self::once())->method('insert')->willReturnCallback(
            static function (OAuthClient $client) use (&$storedClient): void {
                $storedClient = $client;
            }
        );

        $registration = $this->subject->register([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'token_endpoint_auth_method' => $method,
        ]);

        self::assertSame($method, $registration['token_endpoint_auth_method']);
        self::assertMatchesRegularExpression('~^[0-9a-f]{64}$~', $registration['client_secret']);
        self::assertSame(0, $registration['client_secret_expires_at']);
        self::assertInstanceOf(OAuthClient::class, $storedClient);
        self::assertSame('hashed:' . $registration['client_secret'], $storedClient->getSecretHash());
        self::assertFalse($storedClient->isPkceOptional());
    }

    public static function invalidMetadataDataProvider(): array
    {
        return [
            'no redirect uri' => [['client_name' => 'x'], OAuthException::INVALID_REDIRECT_URI],
            'empty redirect uris' => [['redirect_uris' => []], OAuthException::INVALID_REDIRECT_URI],
            'redirect uris no list' => [['redirect_uris' => 'https://a.b/c'], OAuthException::INVALID_REDIRECT_URI],
            'disallowed redirect uri' => [['redirect_uris' => ['http://a.b/c']], OAuthException::INVALID_REDIRECT_URI],
            'unsupported authentication method' => [
                ['redirect_uris' => ['https://a.b/c'], 'token_endpoint_auth_method' => 'private_key_jwt'],
                OAuthException::INVALID_CLIENT_METADATA,
            ],
            'unsupported grant type' => [
                ['redirect_uris' => ['https://a.b/c'], 'grant_types' => ['client_credentials']],
                OAuthException::INVALID_CLIENT_METADATA,
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidMetadataDataProvider')]
    public function registerRefusesInvalidMetadata(array $metadata, string $expectedError): void
    {
        $this->clientRepository->expects(self::never())->method('insert');

        try {
            $this->subject->register($metadata);
            self::fail('No exception was thrown');
        } catch (OAuthException $exception) {
            self::assertSame($expectedError, $exception->getError());
        }
    }
}
