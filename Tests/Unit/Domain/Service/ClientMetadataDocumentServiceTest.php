<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Domain\Service\ClientMetadataDocumentService;
use In2code\In2mcp\Domain\Service\ClientRegistrationService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Domain\Service\GuardedRequestService;
use In2code\In2mcp\Domain\Service\UrlValidationService;
use In2code\In2mcp\Exception\OAuthException;
use In2code\In2mcp\Exception\ResponseTooLargeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ClientMetadataDocumentServiceTest extends UnitTestCase
{
    // A public address as literal, so the test does not depend on name resolution
    private const CLIENT_ID = 'https://93.184.216.34/oauth/client.json';
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    private GuardedRequestService&MockObject $guardedRequestService;
    private ClientMetadataDocumentService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardedRequestService = $this->createMock(GuardedRequestService::class);
        $this->subject = new ClientMetadataDocumentService(
            new UrlValidationService(new ConfigurationService(new ExtensionConfiguration())),
            $this->guardedRequestService,
            new ClientRegistrationService(
                $this->createMock(OAuthClientRepository::class),
                new Random(),
                $this->createMock(PasswordHashFactory::class)
            )
        );
    }

    public static function metadataDocumentUrlDataProvider(): array
    {
        return [
            'https url with path' => ['https://claude.ai/oauth/mcp-oauth-client-metadata', true],
            'http' => ['http://claude.ai/oauth/client.json', false],
            'no path' => ['https://claude.ai', false],
            'root path' => ['https://claude.ai/', false],
            'fragment' => ['https://claude.ai/client.json#x', false],
            'user info' => ['https://user@claude.ai/client.json', false],
            'dot segment' => ['https://claude.ai/a/../client.json', false],
            'hex client id of a registration' => ['15d8e2c57ef1c344e39a91a440c2d127', false],
        ];
    }

    #[Test]
    #[DataProvider('metadataDocumentUrlDataProvider')]
    public function isMetadataDocumentUrlAcceptsHttpsUrlsWithPathOnly(string $clientIdentifier, bool $expected): void
    {
        self::assertSame($expected, ClientMetadataDocumentService::isMetadataDocumentUrl($clientIdentifier));
    }

    #[Test]
    public function fetchReturnsTheClientOfTheDocument(): void
    {
        $this->respondWith(new JsonResponse([
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI, self::REDIRECT_URI],
        ]));

        $client = $this->subject->fetch(self::CLIENT_ID);

        self::assertSame(self::CLIENT_ID, $client->getClientIdentifier());
        self::assertSame('Claude', $client->getClientName());
        self::assertSame([self::REDIRECT_URI], $client->getRedirectUris());
        self::assertSame(OAuthClient::TYPE_METADATA_DOCUMENT, $client->getRegistrationType());
    }

    public static function invalidDocumentDataProvider(): array
    {
        return [
            'other client id' => [['client_id' => 'https://attacker.example.org/client.json']],
            'no redirect uris' => [['redirect_uris' => []]],
            'unsafe redirect uri' => [['redirect_uris' => ['http://attacker.example.org/cb']]],
            'confidential client' => [['token_endpoint_auth_method' => 'private_key_jwt']],
        ];
    }

    #[Test]
    #[DataProvider('invalidDocumentDataProvider')]
    public function fetchRefusesAnInvalidDocument(array $override): void
    {
        $this->respondWith(new JsonResponse(array_replace([
            'client_id' => self::CLIENT_ID,
            'redirect_uris' => [self::REDIRECT_URI],
        ], $override)));

        $this->expectException(OAuthException::class);
        $this->subject->fetch(self::CLIENT_ID);
    }

    #[Test]
    public function fetchRefusesAnAnswerThatIsNoJson(): void
    {
        $response = new Response();
        $response->getBody()->write('<html>');
        $this->respondWith($response);

        $this->expectException(OAuthException::class);
        $this->subject->fetch(self::CLIENT_ID);
    }

    #[Test]
    public function fetchRefusesAnotherStatusThan200(): void
    {
        $this->respondWith(new JsonResponse(['client_id' => self::CLIENT_ID], 302));

        $this->expectException(OAuthException::class);
        $this->subject->fetch(self::CLIENT_ID);
    }

    #[Test]
    public function fetchRefusesADocumentThatIsTooLarge(): void
    {
        $this->guardedRequestService->method('request')->willThrowException(new ResponseTooLargeException());

        $this->expectException(OAuthException::class);
        $this->subject->fetch(self::CLIENT_ID);
    }

    #[Test]
    public function fetchNeverRequestsALocalAddress(): void
    {
        $this->guardedRequestService->expects(self::never())->method('request');

        $this->expectException(OAuthException::class);
        $this->subject->fetch('https://127.0.0.1/client.json');
    }

    private function respondWith(Response $response): void
    {
        $this->guardedRequestService->method('request')->willReturn($response);
    }
}
