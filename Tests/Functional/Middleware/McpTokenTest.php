<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Model\AuthorizationRequest;
use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Domain\Service\AuthorizationService;
use In2code\In2mcp\Domain\Service\ClientRegistrationService;
use In2code\In2mcp\Domain\Service\OAuthTokenService;
use In2code\In2mcp\Middleware\McpToken;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;

final class McpTokenTest extends AbstractMiddlewareTestCase
{
    private const TOKEN_URL = 'https://typo3-testing.local/typo3/mcp/token';
    private const RESOURCE = 'https://typo3-testing.local/typo3/mcp';
    private const REDIRECT_URI = 'http://localhost:33418/callback';
    private const CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const CONFIDENTIAL_REDIRECT_URI = 'https://vertexaisearch.cloud.google.com/oauth-redirect';
    // security-lint: ignore hardcoded-credential - a fixture of the test
    private const CLIENT_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private string $clientIdentifier = '';

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['oauth'] = '1';
        parent::setUp();
        $this->clientIdentifier = $this->registerClient();
    }

    #[Test]
    public function codeIsExchangedForTokensOfTheUserWhoConsented(): void
    {
        $response = $this->requestToken($this->getCodeParameters($this->createCode()));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $tokens = $this->decode($response);
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(OAuthTokenService::ACCESS_TOKEN_LIFETIME, $tokens['expires_in']);
        self::assertSame('mcp:read mcp:write', $tokens['scope']);

        $grant = $this->get(OAuthTokenService::class)->findGrantByAccessToken($tokens['access_token'], self::RESOURCE);
        self::assertSame(self::EDITOR, $grant?->getBackendUserIdentifier());
    }

    #[Test]
    public function codeVerifierWithEveryAllowedCharacterIsAccepted(): void
    {
        $codeVerifier = 'AZaz09-._~' . str_repeat('x', 33);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $parameters = ['code_verifier' => $codeVerifier] + $this->getCodeParameters($this->createCode($codeChallenge));

        self::assertSame(200, $this->requestToken($parameters)->getStatusCode());
    }

    #[Test]
    public function codeIsValidOnce(): void
    {
        $parameters = $this->getCodeParameters($this->createCode());
        $this->requestToken($parameters);

        $response = $this->requestToken($parameters);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_grant', $this->decode($response)['error']);
    }

    #[Test]
    public function wrongCodeVerifierIsRefused(): void
    {
        $parameters = ['code_verifier' => str_repeat('x', 43)] + $this->getCodeParameters($this->createCode());

        self::assertSame('invalid_grant', $this->decode($this->requestToken($parameters))['error']);
    }

    #[Test]
    public function codeOfAnotherClientIsRefused(): void
    {
        $code = $this->createCode();
        $parameters = ['client_id' => $this->registerClient()] + $this->getCodeParameters($code);

        self::assertSame('invalid_grant', $this->decode($this->requestToken($parameters))['error']);
    }

    #[Test]
    public function otherRedirectUriThanInTheAuthorizationIsRefused(): void
    {
        $parameters = ['redirect_uri' => 'http://localhost:1/other'] + $this->getCodeParameters($this->createCode());

        self::assertSame('invalid_grant', $this->decode($this->requestToken($parameters))['error']);
    }

    #[Test]
    public function otherResourceIsRefused(): void
    {
        $parameters = ['resource' => 'https://other.example.org/mcp'] + $this->getCodeParameters($this->createCode());

        self::assertSame('invalid_target', $this->decode($this->requestToken($parameters))['error']);
    }

    #[Test]
    public function unknownClientIsRefusedAsUnauthorized(): void
    {
        $parameters = ['client_id' => 'unknown'] + $this->getCodeParameters($this->createCode());

        self::assertSame(401, $this->requestToken($parameters)->getStatusCode());
    }

    #[Test]
    public function unsupportedGrantTypeIsRefused(): void
    {
        $response = $this->requestToken(['grant_type' => 'password', 'client_id' => $this->clientIdentifier]);

        self::assertSame('unsupported_grant_type', $this->decode($response)['error']);
    }

    #[Test]
    public function refreshTokenIsReplacedByANewOne(): void
    {
        $tokens = $this->decode($this->requestToken($this->getCodeParameters($this->createCode())));

        $response = $this->requestToken($this->getRefreshParameters($tokens['refresh_token']));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $refreshed = $this->decode($response);
        self::assertNotSame($tokens['access_token'], $refreshed['access_token']);
        self::assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);
        self::assertSame('mcp:read mcp:write', $refreshed['scope']);
        $tokenService = $this->get(OAuthTokenService::class);
        self::assertNull($tokenService->findGrantByAccessToken($tokens['access_token'], self::RESOURCE));
        self::assertNotNull($tokenService->findGrantByAccessToken($refreshed['access_token'], self::RESOURCE));
    }

    #[Test]
    public function replayedRefreshTokenRevokesTheWholeGrant(): void
    {
        $tokens = $this->decode($this->requestToken($this->getCodeParameters($this->createCode())));
        $refreshed = $this->decode($this->requestToken($this->getRefreshParameters($tokens['refresh_token'])));

        $replay = $this->requestToken($this->getRefreshParameters($tokens['refresh_token']));

        self::assertSame('invalid_grant', $this->decode($replay)['error']);
        self::assertNull(
            $this->get(OAuthTokenService::class)->findGrantByAccessToken($refreshed['access_token'], self::RESOURCE)
        );
        $afterReplay = $this->requestToken($this->getRefreshParameters($refreshed['refresh_token']));
        self::assertSame('invalid_grant', $this->decode($afterReplay)['error']);
    }

    #[Test]
    public function refreshTokenOfAnotherClientIsRefused(): void
    {
        $tokens = $this->decode($this->requestToken($this->getCodeParameters($this->createCode())));

        $response = $this->requestToken(
            ['client_id' => $this->registerClient()] + $this->getRefreshParameters($tokens['refresh_token'])
        );

        self::assertSame('invalid_grant', $this->decode($response)['error']);
    }

    #[Test]
    public function clientWithSecretAuthenticatesWithBasicAuthenticationAndNeedsNoPkce(): void
    {
        $this->createConfidentialClient();
        $parameters = $this->getConfidentialCodeParameters();

        $response = $this->requestToken($parameters, 'Basic ' . base64_encode('gemini:' . self::CLIENT_SECRET));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $tokens = $this->decode($response);
        $grant = $this->get(OAuthTokenService::class)->findGrantByAccessToken($tokens['access_token'], self::RESOURCE);
        self::assertSame('Gemini Enterprise', $grant?->getClientName());
    }

    #[Test]
    public function clientWithSecretAuthenticatesWithTheSecretInTheForm(): void
    {
        $this->createConfidentialClient();
        $parameters = ['client_secret' => self::CLIENT_SECRET] + $this->getConfidentialCodeParameters();

        self::assertSame(200, $this->requestToken($parameters)->getStatusCode());
    }

    #[Test]
    public function clientWithSecretIsRefusedWithAWrongSecret(): void
    {
        $this->createConfidentialClient();
        $parameters = $this->getConfidentialCodeParameters();

        $response = $this->requestToken($parameters, 'Basic ' . base64_encode('gemini:wrong'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_client', $this->decode($response)['error']);
    }

    #[Test]
    public function clientWithSecretIsRefusedWithoutSecret(): void
    {
        $this->createConfidentialClient();

        self::assertSame(401, $this->requestToken($this->getConfidentialCodeParameters())->getStatusCode());
    }

    #[Test]
    public function registeredClientWithSecretAuthenticatesWithItsSecret(): void
    {
        $secret = $this->registerClientWithSecret();

        $withoutSecret = $this->requestToken($this->getCodeParameters($this->createCode()));
        $withSecret = $this->requestToken(
            ['client_secret' => $secret] + $this->getCodeParameters($this->createCode())
        );

        self::assertSame(401, $withoutSecret->getStatusCode());
        self::assertSame(200, $withSecret->getStatusCode(), (string)$withSecret->getBody());
    }

    #[Test]
    public function registeredClientWithSecretStillNeedsPkce(): void
    {
        $secret = $this->registerClientWithSecret();
        $parameters = ['client_secret' => $secret, 'code_verifier' => '']
            + $this->getCodeParameters($this->createCode(''));

        $response = $this->requestToken($parameters);

        self::assertSame('invalid_grant', $this->decode($response)['error']);
    }

    #[Test]
    public function tokenRequestIsLeftToTheBackendWhileOAuthIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '0';

        $response = $this->requestToken($this->getCodeParameters($this->createCode()));

        self::assertSame(self::NOT_HANDLED_STATUS, $response->getStatusCode());
    }

    private function createConfidentialClient(): void
    {
        $secretHash = $this->get(PasswordHashFactory::class)
            ->getDefaultHashInstance('BE')
            ->getHashedPassword(self::CLIENT_SECRET);
        $this->get(OAuthClientRepository::class)->save(new OAuthClient(
            'gemini',
            'Gemini Enterprise',
            [self::CONFIDENTIAL_REDIRECT_URI],
            OAuthClient::TYPE_MANUAL,
            $secretHash
        ));
    }

    /**
     * @return array<string, string>
     */
    private function getConfidentialCodeParameters(): array
    {
        $request = new AuthorizationRequest(
            'gemini',
            'Gemini Enterprise',
            self::CONFIDENTIAL_REDIRECT_URI,
            '',
            'xyz',
            'mcp:read mcp:write',
            self::RESOURCE
        );
        $url = $this->get(AuthorizationService::class)->getApprovalUrl($request, self::EDITOR, false);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => self::CONFIDENTIAL_REDIRECT_URI,
            'client_id' => 'gemini',
        ];
    }

    private function registerClient(): string
    {
        return $this->get(ClientRegistrationService::class)->register([
            'client_name' => 'Claude Code',
            'redirect_uris' => [self::REDIRECT_URI],
        ])['client_id'];
    }

    // Registers like claude.ai with client_secret_post and makes it the client of the following requests
    private function registerClientWithSecret(): string
    {
        $registration = $this->get(ClientRegistrationService::class)->register([
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI],
            'token_endpoint_auth_method' => 'client_secret_post',
        ]);
        $this->clientIdentifier = $registration['client_id'];
        return $registration['client_secret'];
    }

    private function createCode(string $codeChallenge = self::CODE_CHALLENGE): string
    {
        $request = new AuthorizationRequest(
            $this->clientIdentifier,
            'Claude Code',
            self::REDIRECT_URI,
            $codeChallenge,
            'xyz',
            'mcp:read mcp:write',
            self::RESOURCE
        );
        $url = $this->get(AuthorizationService::class)->getApprovalUrl($request, self::EDITOR, false);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
        return $parameters['code'];
    }

    /**
     * @return array<string, string>
     */
    private function getCodeParameters(string $code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => $this->clientIdentifier,
            'code_verifier' => self::CODE_VERIFIER,
            'resource' => self::RESOURCE,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getRefreshParameters(string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientIdentifier,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function requestToken(array $parameters, string $authorization = ''): ResponseInterface
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($authorization !== '') {
            $headers['Authorization'] = $authorization;
        }
        $request = $this->createBackendRequest(self::TOKEN_URL, 'POST', null, $headers)->withParsedBody($parameters);
        return $this->processWith($this->get(McpToken::class), $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
