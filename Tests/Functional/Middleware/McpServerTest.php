<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Middleware\McpServer;
use Mcp\Schema\RequestMeta;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\StreamFactory;

final class McpServerTest extends AbstractMiddlewareTestCase
{
    private const ENDPOINT_URL = 'https://typo3-testing.local/typo3/mcp';
    private const PROTOCOL_VERSION = '2025-06-18';
    private const STATELESS_PROTOCOL_VERSION = '2026-07-28';
    private const SESSION_HEADER = 'Mcp-Session-Id';
    private const REGISTERED_TOOLS = 30;

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
