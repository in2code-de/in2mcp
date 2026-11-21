<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Middleware\McpAuthorizationServerMetadata;
use In2code\In2mcp\Middleware\McpProtectedResourceMetadata;
use In2code\In2mcp\Middleware\McpServer;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\StreamFactory;

final class McpOAuthMetadataTest extends AbstractMiddlewareTestCase
{
    private const HOST_URL = 'https://typo3-testing.local';
    private const ENDPOINT_URL = self::HOST_URL . '/typo3/mcp';
    private const PROTECTED_RESOURCE_METADATA_URL = self::ENDPOINT_URL . '/.well-known/oauth-protected-resource';
    private const AUTHORIZATION_SERVER_METADATA_URL
        = self::HOST_URL . '/.well-known/oauth-authorization-server/typo3/mcp';

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['oauth'] = '1';
        parent::setUp();
    }

    #[Test]
    public function protectedResourceMetadataNamesTheEndpointAsItsOwnAuthorizationServer(): void
    {
        $response = $this->processWith(
            $this->get(McpProtectedResourceMetadata::class),
            $this->createBackendRequest(self::PROTECTED_RESOURCE_METADATA_URL, 'GET', null, [])
        );

        self::assertSame(200, $response->getStatusCode());
        $metadata = $this->decode($response);
        self::assertSame(self::ENDPOINT_URL, $metadata['resource']);
        self::assertSame([self::ENDPOINT_URL], $metadata['authorization_servers']);
        self::assertSame(['mcp:read', 'mcp:write'], $metadata['scopes_supported']);
    }

    #[Test]
    public function authorizationServerMetadataAnnouncesTheEndpointsBelowTheMcpEndpoint(): void
    {
        $response = $this->processWith(
            $this->get(McpAuthorizationServerMetadata::class),
            $this->createBackendRequest(self::AUTHORIZATION_SERVER_METADATA_URL, 'GET', null, [])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $metadata = $this->decode($response);
        self::assertSame(self::ENDPOINT_URL, $metadata['issuer']);
        self::assertSame(self::ENDPOINT_URL . '/authorize', $metadata['authorization_endpoint']);
        self::assertSame(self::ENDPOINT_URL . '/token', $metadata['token_endpoint']);
        self::assertSame(self::ENDPOINT_URL . '/register', $metadata['registration_endpoint']);
        self::assertSame(['S256'], $metadata['code_challenge_methods_supported']);
        self::assertFalse($metadata['client_id_metadata_document_supported']);
    }

    #[Test]
    public function authorizationServerMetadataAnnouncesClientMetadataDocumentsWhileSwitchedOn(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauthClientMetadataDocuments'] = '1';

        $response = $this->processWith(
            $this->get(McpAuthorizationServerMetadata::class),
            $this->createBackendRequest(self::AUTHORIZATION_SERVER_METADATA_URL, 'GET', null, [])
        );

        self::assertTrue($this->decode($response)['client_id_metadata_document_supported']);
    }

    #[Test]
    public function metadataFollowsAConfiguredEndpointPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['mcpServerPath'] = 'ai';

        $response = $this->processWith(
            $this->get(McpAuthorizationServerMetadata::class),
            $this->createBackendRequest(
                self::HOST_URL . '/.well-known/oauth-authorization-server/typo3/ai',
                'GET',
                null,
                []
            )
        );

        self::assertSame(self::HOST_URL . '/typo3/ai', $this->decode($response)['issuer']);
    }

    #[Test]
    public function metadataIsLeftToTheNextMiddlewareWhileOAuthIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '0';

        $response = $this->processWith(
            $this->get(McpAuthorizationServerMetadata::class),
            $this->createBackendRequest(self::AUTHORIZATION_SERVER_METADATA_URL, 'GET', null, [])
        );

        self::assertSame(self::NOT_HANDLED_STATUS, $response->getStatusCode());
    }

    #[Test]
    public function metadataIsRefusedForAClientAddressOutsideOfTheAllowedAddresses(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['mcpServerAllowedIps'] = '198.51.100.0/24';

        $response = $this->processWith(
            $this->get(McpProtectedResourceMetadata::class),
            $this->createBackendRequest(self::PROTECTED_RESOURCE_METADATA_URL, 'GET', null, [])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function metadataRefusesAPost(): void
    {
        $response = $this->processWith(
            $this->get(McpProtectedResourceMetadata::class),
            $this->createBackendRequest(self::PROTECTED_RESOURCE_METADATA_URL, 'POST', null, [])
        );

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function unauthorizedResponseOfTheEndpointPointsToTheMetadata(): void
    {
        $response = $this->processWith($this->get(McpServer::class), $this->createMcpRequest());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Bearer realm="TYPO3 in2mcp", resource_metadata="' . self::PROTECTED_RESOURCE_METADATA_URL . '",'
            . ' scope="mcp:read mcp:write"',
            $response->getHeaderLine('WWW-Authenticate')
        );
    }

    #[Test]
    public function unauthorizedResponseOfTheEndpointHasNoMetadataWhileOAuthIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '0';

        $response = $this->processWith($this->get(McpServer::class), $this->createMcpRequest());

        self::assertSame('Bearer realm="TYPO3 in2mcp"', $response->getHeaderLine('WWW-Authenticate'));
    }

    private function createMcpRequest(): ServerRequestInterface
    {
        return $this->createBackendRequest(
            self::ENDPOINT_URL,
            'POST',
            (new StreamFactory())->createStream('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream']
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
