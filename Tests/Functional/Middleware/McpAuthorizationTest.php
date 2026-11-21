<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Service\AuthorizationService;
use In2code\In2mcp\Domain\Service\ClientRegistrationService;
use In2code\In2mcp\Middleware\McpAuthorization;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

final class McpAuthorizationTest extends AbstractMiddlewareTestCase
{
    private const AUTHORIZATION_URL = 'https://typo3-testing.local/typo3/mcp/authorize';
    private const RESOURCE = 'https://typo3-testing.local/typo3/mcp';
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
    private const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private string $clientIdentifier = '';

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['oauth'] = '1';
        parent::setUp();
        $this->clientIdentifier = $this->get(ClientRegistrationService::class)->register([
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI],
        ])['client_id'];
    }

    #[Test]
    public function validRequestIsHandedOverToTheConsentPage(): void
    {
        $response = $this->authorize([]);

        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('https://typo3-testing.local/typo3/in2mcp/authorize?request=', $location);
        self::assertStringNotContainsString('token=', $location);

        parse_str((string)parse_url($location, PHP_URL_QUERY), $parameters);
        $request = $this->get(AuthorizationService::class)->findRequest($parameters['request']);
        self::assertNotNull($request);
        self::assertSame($this->clientIdentifier, $request->getClientIdentifier());
        self::assertSame(self::RESOURCE, $request->getResource());
    }

    #[Test]
    public function invalidRequestOfAKnownClientIsRedirectedBackWithTheError(): void
    {
        $response = $this->authorize(['code_challenge_method' => 'plain']);

        self::assertSame(303, $response->getStatusCode());
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $parameters);
        self::assertStringStartsWith(self::REDIRECT_URI . '?', $response->getHeaderLine('Location'));
        self::assertSame('invalid_request', $parameters['error']);
        self::assertSame('xyz', $parameters['state']);
        self::assertSame(self::RESOURCE, $parameters['iss']);
    }

    #[Test]
    public function unregisteredRedirectUriIsShownAndNeverRedirectedTo(): void
    {
        $response = $this->authorize(['redirect_uri' => 'https://attacker.example.org/callback']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function unknownClientIsRefused(): void
    {
        self::assertSame(400, $this->authorize(['client_id' => 'unknown'])->getStatusCode());
    }

    #[Test]
    public function authorizationIsLeftToTheBackendWhileOAuthIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '0';

        self::assertSame(self::NOT_HANDLED_STATUS, $this->authorize([])->getStatusCode());
    }

    /**
     * @param array<string, string> $override
     */
    private function authorize(array $override): ResponseInterface
    {
        $query = array_replace([
            'response_type' => 'code',
            'client_id' => $this->clientIdentifier,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => 'mcp:read mcp:write',
            'resource' => self::RESOURCE,
        ], $override);
        $request = $this->createBackendRequest(
            self::AUTHORIZATION_URL . '?' . http_build_query($query),
            'GET',
            null,
            []
        )->withQueryParams($query);
        return $this->processWith($this->get(McpAuthorization::class), $request);
    }
}
