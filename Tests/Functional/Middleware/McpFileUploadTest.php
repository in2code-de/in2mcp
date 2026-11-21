<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Domain\Model\AccessScope;
use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Domain\Service\FileUploadService;
use In2code\In2mcp\Domain\Service\OAuthTokenService;
use In2code\In2mcp\Domain\Service\UploadTokenService;
use In2code\In2mcp\Middleware\McpFileUpload;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Http\UploadedFile;

final class McpFileUploadTest extends AbstractMiddlewareTestCase
{
    private const UPLOAD_URL = 'https://typo3-testing.local/typo3/mcp/upload';
    private const EDITOR_FOLDER = '1:/editor/';
    private const PRIVATE_FOLDER = '1:/private/';
    private const CONTENT = 'Uploaded by the client';
    private const GROUP_WITHOUT_MCP = 2;
    private const MALFORMED_TOKEN = 'not-a-token';
    private const UNKNOWN_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/in2mcp/Tests/Functional/Fixtures/Files/fileadmin/' => 'fileadmin/',
    ];

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['fileUpload'] = '1';
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Files.csv');
        // A token is only accepted for a user who still has an api key
        $this->get(ApiKeyService::class)->createApiKey('admin');
        $this->get(ApiKeyService::class)->createApiKey('editor');
    }

    #[Test]
    public function tokenOfCreateFileUploadStoresTheFileInItsFolder(): void
    {
        $this->loginAs(self::EDITOR);
        $token = $this->callTool('create_file_upload', ['targetFolder' => self::EDITOR_FOLDER])['token'];

        $response = $this->upload($token, 'notes.txt');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertTrue($this->decode($response)['imported']);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/editor/notes.txt', self::CONTENT);
        self::assertTrue($this->isFileIndexed('/editor/notes.txt'));
    }

    #[Test]
    public function fileNameOfTheTokenWinsOverTheNameOfTheClient(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, 'fixed-name.txt');

        $response = $this->upload($token, 'chosen-by-client.txt');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertFileExists($this->instancePath . '/fileadmin/editor/fixed-name.txt');
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/editor/chosen-by-client.txt');
    }

    #[Test]
    public function tokenIsValidForOneUploadOnly(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $this->upload($token, 'first.txt');

        $response = $this->upload($token, 'second.txt');

        self::assertSame(401, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/editor/second.txt');
    }

    #[Test]
    public function unknownTokenIsRefused(): void
    {
        self::assertSame(401, $this->upload(self::UNKNOWN_TOKEN, 'unknown.txt')->getStatusCode());
    }

    #[Test]
    public function malformedTokenIsRefused(): void
    {
        self::assertSame(401, $this->upload(self::MALFORMED_TOKEN, 'malformed.txt')->getStatusCode());
    }

    #[Test]
    public function uploadWithoutTokenIsRefused(): void
    {
        self::assertSame(401, $this->upload('', 'without-token.txt')->getStatusCode());
    }

    #[Test]
    public function userWhoLostMcpAccessAfterTheTokenWasCreatedIsRefused(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['usergroup' => (string)self::GROUP_WITHOUT_MCP], ['uid' => self::EDITOR]);

        $response = $this->upload($token, 'lost-access.txt');

        self::assertSame(401, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/editor/lost-access.txt');
    }

    #[Test]
    public function revokedApiKeyEndsTheTokensOfItsUser(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $this->get(ApiKeyService::class)->revokeApiKey('editor');

        $response = $this->upload($token, 'revoked.txt');

        self::assertSame(401, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/editor/revoked.txt');
    }

    #[Test]
    public function grantWithWriteAccessKeepsTheTokensOfAUserWithoutApiKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '1';
        $this->issueTokens(self::EDITOR, AccessScope::Write);
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $this->get(ApiKeyService::class)->revokeApiKey('editor');

        $response = $this->upload($token, 'granted.txt');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
    }

    #[Test]
    public function grantWithReadAccessOnlyDoesNotKeepTheTokens(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['oauth'] = '1';
        $this->issueTokens(self::EDITOR, AccessScope::Read);
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $this->get(ApiKeyService::class)->revokeApiKey('editor');

        self::assertSame(401, $this->upload($token, 'read-only.txt')->getStatusCode());
    }

    #[Test]
    public function grantDoesNotKeepTheTokensWhileOAuthIsSwitchedOff(): void
    {
        $this->issueTokens(self::EDITOR, AccessScope::Write);
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $this->get(ApiKeyService::class)->revokeApiKey('editor');

        self::assertSame(401, $this->upload($token, 'oauth-off.txt')->getStatusCode());
    }

    #[Test]
    public function apiKeyDoesNotKeepTheTokensWhileApiKeysAreSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['apiKeyAuthentication'] = '0';
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');

        self::assertSame(401, $this->upload($token, 'api-keys-off.txt')->getStatusCode());
    }

    #[Test]
    public function userIsRefusedWhileTheBackendIsLockedToAdministrators(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $GLOBALS['TYPO3_CONF_VARS']['BE']['adminOnly'] = 1;

        self::assertSame(401, $this->upload($token, 'locked.txt')->getStatusCode());
    }

    #[Test]
    public function folderOutsideOfTheFileMountsIsRefusedAtUploadTime(): void
    {
        $token = $this->createToken(self::EDITOR, self::PRIVATE_FOLDER, '');

        $response = $this->upload($token, 'outside.txt');

        self::assertSame(400, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/private/outside.txt');
    }

    #[Test]
    public function phpFileIsRefused(): void
    {
        $token = $this->createToken(self::ADMIN, self::EDITOR_FOLDER, '');

        $response = $this->upload($token, 'shell.php');

        self::assertSame(400, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/editor/shell.php');
    }

    #[Test]
    public function fileLargerThanTheMaximumSizeIsRefused(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $maximumSize = strlen(self::CONTENT) - 1;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['fileImportMaximumSize'] = (string)$maximumSize;

        $response = $this->upload($token, 'too-large.txt');

        self::assertSame(400, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/editor/too-large.txt');
    }

    #[Test]
    public function requestWithoutFileIsRefused(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $request = $this->createUploadRequest('POST', $token);

        self::assertSame(400, $this->process($request)->getStatusCode());
    }

    #[Test]
    public function otherMethodThanPostIsRefused(): void
    {
        $response = $this->process($this->createUploadRequest('GET', self::UNKNOWN_TOKEN));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function clientAddressOutsideOfTheAllowedAddressesIsRefused(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['mcpServerAllowedIps'] = '198.51.100.0/24';

        self::assertSame(403, $this->upload($token, 'foreign-address.txt')->getStatusCode());
    }

    #[Test]
    public function switchedOffUploadLeavesTheRequestToTheBackend(): void
    {
        $token = $this->createToken(self::EDITOR, self::EDITOR_FOLDER, '');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['fileUpload'] = '0';

        self::assertSame(self::NOT_HANDLED_STATUS, $this->upload($token, 'switched-off.txt')->getStatusCode());
    }

    private function issueTokens(int $userUid, AccessScope $scope): void
    {
        $this->get(OAuthTokenService::class)->issue(
            $userUid,
            'client',
            AccessScope::toScopeString(AccessScope::fromScopeString($scope->value)),
            'https://typo3-testing.local/typo3/mcp'
        );
    }

    private function createToken(int $userUid, string $folder, string $fileName): string
    {
        return $this->get(UploadTokenService::class)->create($userUid, $folder, $fileName);
    }

    private function upload(string $token, string $clientFileName): ResponseInterface
    {
        $stream = (new StreamFactory())->createStream(self::CONTENT);
        $uploadedFile = new UploadedFile($stream, strlen(self::CONTENT), UPLOAD_ERR_OK, $clientFileName, 'text/plain');
        $request = $this->createUploadRequest('POST', $token)
            ->withUploadedFiles([FileUploadService::FILE_FIELD => $uploadedFile]);
        return $this->process($request);
    }

    private function createUploadRequest(string $method, string $token): ServerRequestInterface
    {
        $request = $this->createBackendRequest(self::UPLOAD_URL, $method, null, []);
        return $token === '' ? $request : $request->withHeader(FileUploadService::TOKEN_HEADER, $token);
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        return $this->processWith($this->get(McpFileUpload::class), $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function isFileIndexed(string $identifier): bool
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file');
        return (int)$queryBuilder->count('uid')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'identifier',
                    $queryBuilder->createNamedParameter($identifier, Connection::PARAM_STR)
                )
            )
            ->executeQuery()
            ->fetchOne() === 1;
    }
}
