<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Model\AccessScope;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Domain\Repository\OAuthGrantRepository;
use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Domain\Service\OAuthTokenService;
use In2code\In2mcp\Middleware\McpServer;
use Mcp\Schema\RequestMeta;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\StreamFactory;

final class McpServerTest extends AbstractMiddlewareTestCase
{
    private const ENDPOINT_URL = 'https://typo3-testing.local/typo3/mcp';
    private const PROTOCOL_VERSION = '2025-06-18';
    private const STATELESS_PROTOCOL_VERSION = '2026-07-28';
    private const SESSION_HEADER = 'Mcp-Session-Id';
    private const REGISTERED_TOOLS = 32;
    private const RATE_LIMITED_ADDRESS = '203.0.113.20';
    private const FAILED_AUTHENTICATIONS_LIMIT = 3;
    private const RATE_LIMITER_CACHE = 'ratelimiter';

    #[Test]
    public function administratorListsEveryToolWithItsAnnotations(): void
    {
        $sessionId = $this->initialize($this->createApiKey('admin'));

        $result = $this->send($this->createApiKey('admin'), 'tools/list', [], $sessionId);

        self::assertCount(self::REGISTERED_TOOLS, $result['result']['tools']);
        $tools = array_column($result['result']['tools'], null, 'name');
        self::assertTrue($tools['get_page_tree']['annotations']['readOnlyHint']);
        self::assertTrue($tools['delete_record']['annotations']['destructiveHint']);
        self::assertTrue($tools['add_file_from_url']['annotations']['openWorldHint']);
    }

    #[Test]
    public function editorWithMcpAccessRunsAToolAsItself(): void
    {
        $apiKey = $this->createApiKey('editor');
        $sessionId = $this->initialize($apiKey);

        $result = $this->send(
            $apiKey,
            'tools/call',
            ['name' => 'get_backend_user', 'arguments' => (object)[]],
            $sessionId
        );

        self::assertFalse($result['result']['isError'] ?? false);
        self::assertSame('editor', $result['result']['structuredContent']['username']);
    }

    #[Test]
    public function refusedToolCallBecomesAnErrorResultWithAClientMessage(): void
    {
        $apiKey = $this->createApiKey('editor');
        $sessionId = $this->initialize($apiKey);

        $result = $this->send(
            $apiKey,
            'tools/call',
            ['name' => 'get_page', 'arguments' => ['pageUid' => 4]],
            $sessionId
        );

        self::assertTrue($result['result']['isError']);
        self::assertStringContainsString('not allowed to see it', $result['result']['content'][0]['text']);
    }

    #[Test]
    public function clientWithoutHandshakeListsTheTools(): void
    {
        $response = $this->process($this->createStatelessRequest($this->createApiKey('admin'), 'tools/list'));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertCount(self::REGISTERED_TOOLS, $this->decode($response)['result']['tools']);
    }

    #[Test]
    public function clientWithoutHandshakeRunsAToolAsTheUserOfTheApiKey(): void
    {
        $response = $this->process($this->createStatelessRequest(
            $this->createApiKey('editor'),
            'tools/call',
            ['name' => 'get_backend_user', 'arguments' => (object)[]]
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('editor', $this->decode($response)['result']['structuredContent']['username']);
    }

    #[Test]
    public function clientWithoutHandshakeIsRefusedWithoutApiKey(): void
    {
        $response = $this->process($this->createStatelessRequest('', 'tools/list'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function editorWithoutMcpAccessIsRefused(): void
    {
        $response = $this->process($this->createRequest($this->createApiKey('editor-without-mcp'), 'initialize'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function wrongApiKeyIsRefused(): void
    {
        $apiKey = $this->createApiKey('admin');
        $wrongApiKey = substr($apiKey, 0, -4) . 'xxxx';

        self::assertSame(401, $this->process($this->createRequest($wrongApiKey, 'initialize'))->getStatusCode());
    }

    #[Test]
    public function malformedApiKeyIsRefused(): void
    {
        self::assertSame(401, $this->process($this->createRequest('too-short', 'initialize'))->getStatusCode());
    }

    #[Test]
    public function requestWithoutApiKeyIsRefused(): void
    {
        self::assertSame(401, $this->process($this->createRequest('', 'initialize'))->getStatusCode());
    }

    #[Test]
    public function revokedApiKeyIsRefused(): void
    {
        $apiKey = $this->createApiKey('admin');
        $this->get(ApiKeyService::class)->revokeApiKey('admin');

        self::assertSame(401, $this->process($this->createRequest($apiKey, 'initialize'))->getStatusCode());
    }

    #[Test]
    public function editorIsRefusedWhileTheBackendIsLockedToAdministrators(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'] = 1;

        $response = $this->process($this->createRequest($this->createApiKey('editor'), 'initialize'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function administratorIsAcceptedWhileTheBackendIsLockedToAdministrators(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'] = 1;

        self::assertNotSame('', $this->initialize($this->createApiKey('admin')));
    }

    #[Test]
    public function tooManyFailedAuthenticationsBlockEvenAValidApiKey(): void
    {
        $this->limitFailedAuthentications();
        $apiKey = $this->createApiKey('admin');
        for ($attempt = 0; $attempt < self::FAILED_AUTHENTICATIONS_LIMIT; $attempt++) {
            self::assertSame(401, $this->process($this->createRequest('too-short', 'initialize'))->getStatusCode());
        }

        $response = $this->process($this->createRequest($apiKey, 'initialize'));

        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function successfulAuthenticationResetsTheCountOfFailures(): void
    {
        $this->limitFailedAuthentications();
        $apiKey = $this->createApiKey('admin');
        for ($attempt = 1; $attempt < self::FAILED_AUTHENTICATIONS_LIMIT; $attempt++) {
            $this->process($this->createRequest('too-short', 'initialize'));
        }
        $this->initialize($apiKey);

        for ($attempt = 1; $attempt < self::FAILED_AUTHENTICATIONS_LIMIT; $attempt++) {
            self::assertSame(401, $this->process($this->createRequest('too-short', 'initialize'))->getStatusCode());
        }
        self::assertNotSame('', $this->initialize($apiKey));
    }

    #[Test]
    public function clientAddressOutsideOfTheAllowedAddressesIsRefused(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['mcpServerAllowedIps'] = '198.51.100.0/24';

        $response = $this->process($this->createRequest($this->createApiKey('admin'), 'initialize'));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function clientAddressInsideOfTheAllowedAddressesIsAccepted(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['mcpServerAllowedIps'] = '192.0.2.0/24';

        self::assertNotSame('', $this->initialize($this->createApiKey('admin')));
    }

    #[Test]
    public function switchedOffServerLeavesTheRequestToTheBackend(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['mcpServer'] = '0';

        $response = $this->process($this->createRequest($this->createApiKey('admin'), 'initialize'));

        self::assertSame(self::NOT_HANDLED_STATUS, $response->getStatusCode());
    }

    #[Test]
    public function accessTokenRunsAToolAsTheUserOfItsGrant(): void
    {
        $this->enableOAuth();

        $response = $this->process($this->createStatelessRequest(
            $this->issueAccessToken(self::EDITOR, AccessScope::Write),
            'tools/call',
            ['name' => 'get_backend_user', 'arguments' => (object)[]]
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('editor', $this->decode($response)['result']['structuredContent']['username']);
    }

    #[Test]
    public function accessTokenWithWriteAccessListsEveryTool(): void
    {
        $this->enableOAuth();

        $response = $this->listTools($this->issueAccessToken(self::ADMIN, AccessScope::Write));

        self::assertCount(self::REGISTERED_TOOLS, $this->decode($response)['result']['tools']);
    }

    #[Test]
    public function accessTokenWithReadAccessListsReadOnlyToolsOnly(): void
    {
        $this->enableOAuth();

        $response = $this->listTools($this->issueAccessToken(self::ADMIN, AccessScope::Read));

        $tools = $this->decode($response)['result']['tools'];
        self::assertNotSame([], $tools);
        self::assertLessThan(self::REGISTERED_TOOLS, count($tools));
        foreach ($tools as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint'], $tool['name'] . ' is not read only');
        }
    }

    #[Test]
    public function accessTokenWithReadAccessCanNotCallAWritingTool(): void
    {
        $this->enableOAuth();

        $response = $this->process($this->createStatelessRequest(
            $this->issueAccessToken(self::ADMIN, AccessScope::Read),
            'tools/call',
            ['name' => 'clear_cache', 'arguments' => (object)[]]
        ));

        $result = $this->decode($response);
        self::assertTrue(isset($result['error']) || ($result['result']['isError'] ?? false));
    }

    #[Test]
    public function accessTokenIsRefusedWhileOAuthIsSwitchedOff(): void
    {
        $accessToken = $this->issueAccessToken(self::ADMIN, AccessScope::Write);

        self::assertSame(401, $this->listTools($accessToken)->getStatusCode());
    }

    #[Test]
    public function accessTokenOfAnotherEndpointIsRefused(): void
    {
        $this->enableOAuth();
        $this->createOAuthClient('client');
        $accessToken = $this->get(OAuthTokenService::class)->issue(
            self::ADMIN,
            'client',
            AccessScope::Write->value,
            'https://other.example.org/typo3/mcp'
        )->getAccessToken();

        self::assertSame(401, $this->listTools($accessToken)->getStatusCode());
    }

    #[Test]
    public function expiredAccessTokenIsRefused(): void
    {
        $this->enableOAuth();
        $accessToken = $this->issueAccessToken(self::ADMIN, AccessScope::Write);
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(OAuthGrantRepository::TABLE_NAME)
            ->update(
                OAuthGrantRepository::TABLE_NAME,
                ['access_token_expires' => time() - 1],
                ['be_user' => self::ADMIN]
            );

        self::assertSame(401, $this->listTools($accessToken)->getStatusCode());
    }

    #[Test]
    public function accessTokenOfADeletedClientIsRefused(): void
    {
        $this->enableOAuth();
        $accessToken = $this->issueAccessToken(self::ADMIN, AccessScope::Write);
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(OAuthClientRepository::TABLE_NAME)
            ->delete(OAuthClientRepository::TABLE_NAME, ['client_id' => 'client']);

        self::assertSame(401, $this->listTools($accessToken)->getStatusCode());
    }

    #[Test]
    public function unknownAccessTokenIsRefused(): void
    {
        $this->enableOAuth();
        $accessToken = OAuthTokenService::ACCESS_TOKEN_PREFIX . str_repeat('a', 64);

        self::assertSame(401, $this->listTools($accessToken)->getStatusCode());
    }

    #[Test]
    public function accessTokenOfEditorWithoutMcpAccessIsRefused(): void
    {
        $this->enableOAuth();
        $accessToken = $this->issueAccessToken(self::EDITOR_WITHOUT_MCP, AccessScope::Write);

        self::assertSame(401, $this->listTools($accessToken)->getStatusCode());
    }

    #[Test]
    public function apiKeyIsRefusedWhileApiKeysAreSwitchedOff(): void
    {
        $apiKey = $this->createApiKey('admin');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['apiKeyAuthentication'] = '0';

        self::assertSame(401, $this->listTools($apiKey)->getStatusCode());
    }

    // TYPO3 fills a missing setting from ext_conf_template.txt like an update from 5.x does - api keys are off then
    #[Test]
    public function apiKeyIsRefusedWhileTheSettingWasNeverSaved(): void
    {
        $apiKey = $this->createApiKey('admin');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['apiKeyAuthentication']);

        self::assertSame(401, $this->listTools($apiKey)->getStatusCode());
    }

    #[Test]
    public function accessTokenIsAcceptedWhileApiKeysAreSwitchedOff(): void
    {
        $this->enableOAuth();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['apiKeyAuthentication'] = '0';
        $accessToken = $this->issueAccessToken(self::ADMIN, AccessScope::Write);

        self::assertSame(200, $this->listTools($accessToken)->getStatusCode());
    }

    private function listTools(string $credential): ResponseInterface
    {
        return $this->process($this->createStatelessRequest($credential, 'tools/list'));
    }

    private function enableOAuth(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '1';
    }

    private function issueAccessToken(int $userUid, AccessScope $scope): string
    {
        $this->createOAuthClient('client');
        return $this->get(OAuthTokenService::class)->issue(
            $userUid,
            'client',
            AccessScope::toScopeString(AccessScope::fromScopeString($scope->value)),
            self::ENDPOINT_URL
        )->getAccessToken();
    }

    // The counter lives in a cache of the test instance, which outlasts a single test and even a test run
    private function limitFailedAuthentications(): void
    {
        $this->get(CacheManager::class)->getCache(self::RATE_LIMITER_CACHE)->flush();
        $this->clientAddress = self::RATE_LIMITED_ADDRESS;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['in2mcp'] = [
            'limit' => self::FAILED_AUTHENTICATIONS_LIMIT,
        ];
    }

    private function createApiKey(string $username): string
    {
        return $this->get(ApiKeyService::class)->createApiKey($username);
    }

    private function initialize(string $apiKey): string
    {
        $response = $this->process($this->createRequest($apiKey, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => ['name' => 'functional-test', 'version' => '1.0'],
        ]));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $sessionId = $response->getHeaderLine(self::SESSION_HEADER);
        self::assertNotSame('', $sessionId);
        $this->process($this->createRequest($apiKey, 'notifications/initialized', [], $sessionId, false));
        return $sessionId;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function send(string $apiKey, string $method, array $parameters, string $sessionId): array
    {
        $response = $this->process($this->createRequest($apiKey, $method, $parameters, $sessionId));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        if (str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            preg_match('~^data: (.+)$~m', $body, $matches);
            $body = $matches[1] ?? '';
        }
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function createRequest(
        string $apiKey,
        string $method,
        array $parameters = [],
        string $sessionId = '',
        bool $hasIdentifier = true
    ): ServerRequestInterface {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object)$parameters];
        if ($hasIdentifier) {
            $message['id'] = 1;
        }

        $request = $this->createBackendRequest(
            self::ENDPOINT_URL,
            'POST',
            (new StreamFactory())->createStream(json_encode($message, JSON_THROW_ON_ERROR)),
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            ]
        );
        if ($apiKey !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $apiKey);
        }
        if ($sessionId !== '') {
            $request = $request->withHeader(self::SESSION_HEADER, $sessionId);
        }
        return $request;
    }

    /**
     * A client of the revision without handshake names the revision in every request instead of a session
     *
     * @param array<string, mixed> $parameters
     */
    private function createStatelessRequest(
        string $apiKey,
        string $method,
        array $parameters = []
    ): ServerRequestInterface {
        $parameters['_meta'] = [
            RequestMeta::PROTOCOL_VERSION => self::STATELESS_PROTOCOL_VERSION,
            RequestMeta::CLIENT_CAPABILITIES => (object)[],
            RequestMeta::CLIENT_INFO => ['name' => 'functional-test', 'version' => '1.0'],
        ];
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $parameters];

        $request = $this->createRequest($apiKey, $method)
            ->withBody((new StreamFactory())->createStream(json_encode($message, JSON_THROW_ON_ERROR)))
            ->withHeader('MCP-Protocol-Version', self::STATELESS_PROTOCOL_VERSION)
            ->withHeader('Mcp-Method', $method);
        if (is_string($parameters['name'] ?? null)) {
            $request = $request->withHeader('Mcp-Name', $parameters['name']);
        }
        return $request;
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        return $this->processWith($this->get(McpServer::class), $request);
    }
}
