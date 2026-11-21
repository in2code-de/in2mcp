<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Middleware\McpClientRegistration;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\StreamFactory;

final class McpClientRegistrationTest extends AbstractMiddlewareTestCase
{
    private const REGISTRATION_URL = 'https://typo3-testing.local/typo3/mcp/register';
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
    private const RATE_LIMITED_ADDRESS = '203.0.113.30';

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['oauth'] = '1';
        parent::setUp();
    }

    #[Test]
    public function clientIsRegisteredWithItsRedirectUris(): void
    {
        $response = $this->register(['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT_URI]]);

        self::assertSame(201, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $registration = $this->decode($response);
        $client = $this->get(OAuthClientRepository::class)->findByClientIdentifier($registration['client_id']);
        self::assertNotNull($client);
        self::assertSame('Claude', $client->getClientName());
        self::assertSame([self::REDIRECT_URI], $client->getRedirectUris());
    }

    #[Test]
    public function clientWithAnUnsafeRedirectUriIsRefused(): void
    {
        $response = $this->register(['redirect_uris' => ['http://attacker.example.org/callback']]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_redirect_uri', $this->decode($response)['error']);
    }

    #[Test]
    public function bodyThatIsNoJsonObjectIsRefused(): void
    {
        $response = $this->processWith(
            $this->get(McpClientRegistration::class),
            $this->createBackendRequest(
                self::REGISTRATION_URL,
                'POST',
                (new StreamFactory())->createStream('"just a string"'),
                ['Content-Type' => 'application/json']
            )
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_client_metadata', $this->decode($response)['error']);
    }

    #[Test]
    public function getIsRefused(): void
    {
        $response = $this->processWith(
            $this->get(McpClientRegistration::class),
            $this->createBackendRequest(self::REGISTRATION_URL, 'GET', null, [])
        );

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function registrationsAreLimitedPerAddress(): void
    {
        $this->get(CacheManager::class)->getCache('ratelimiter')->flush();
        $this->clientAddress = self::RATE_LIMITED_ADDRESS;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['in2mcp'] = ['limit' => 2];
        $metadata = ['redirect_uris' => [self::REDIRECT_URI]];
        $this->register($metadata);
        $this->register($metadata);

        self::assertSame(429, $this->register($metadata)->getStatusCode());
    }

    #[Test]
    public function registrationIsLeftToTheBackendWhileOAuthIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '0';

        $response = $this->register(['redirect_uris' => [self::REDIRECT_URI]]);

        self::assertSame(self::NOT_HANDLED_STATUS, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function register(array $metadata): ResponseInterface
    {
        return $this->processWith(
            $this->get(McpClientRegistration::class),
            $this->createBackendRequest(
                self::REGISTRATION_URL,
                'POST',
                (new StreamFactory())->createStream(json_encode($metadata, JSON_THROW_ON_ERROR)),
                ['Content-Type' => 'application/json']
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
