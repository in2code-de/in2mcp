<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Middleware\McpServer;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;

final class McpServerTest extends AbstractFunctionalTestCase
{
    private const ENDPOINT_URL = 'https://typo3-testing.local/typo3/mcp';
    private const CLIENT_ADDRESS = '192.0.2.10';
    private const PROTOCOL_VERSION = '2025-06-18';
    private const SESSION_HEADER = 'Mcp-Session-Id';
    private const NOT_HANDLED_STATUS = 418;
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

        $request = new ServerRequest(
            self::ENDPOINT_URL,
            'POST',
            (new StreamFactory())->createStream(json_encode($message, JSON_THROW_ON_ERROR)),
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
            ],
            ['HTTP_HOST' => 'typo3-testing.local', 'REMOTE_ADDR' => self::CLIENT_ADDRESS, 'HTTPS' => 'on']
        );
        if ($apiKey !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $apiKey);
        }
        if ($sessionId !== '') {
            $request = $request->withHeader(self::SESSION_HEADER, $sessionId);
        }

        return $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        $backend = new class (self::NOT_HANDLED_STATUS) implements RequestHandlerInterface {
            public function __construct(private readonly int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(null, $this->status);
            }
        };
        return $this->get(McpServer::class)->process($request, $backend);
    }
}
