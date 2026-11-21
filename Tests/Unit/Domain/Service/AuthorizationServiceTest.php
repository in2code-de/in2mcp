<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Model\AuthorizationRequest;
use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Service\AuthorizationService;
use In2code\In2mcp\Domain\Service\OAuthClientService;
use In2code\In2mcp\Exception\OAuthException;
use In2code\In2mcp\Exception\OAuthRedirectException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AuthorizationServiceTest extends UnitTestCase
{
    // The cache backend fetches a logger through the LogManager singleton
    protected bool $resetSingletonInstances = true;

    private const RESOURCE = 'https://example.org/typo3/mcp';
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
    private const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private AuthorizationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $clientService = $this->createMock(OAuthClientService::class);
        $clientService->method('findForAuthorization')->willReturnCallback(
            static fn (string $identifier): ?OAuthClient => $identifier === 'client'
                ? new OAuthClient('client', 'Claude', [self::REDIRECT_URI], OAuthClient::TYPE_DYNAMIC)
                : null
        );
        $this->subject = new AuthorizationService(
            new VariableFrontend('in2mcp_oauth_test', new TransientMemoryBackend()),
            $clientService,
            new Random()
        );
    }

    #[Test]
    public function createRequestTakesOverTheParametersOfTheClient(): void
    {
        $request = $this->subject->createRequest($this->getQuery(), self::RESOURCE);

        self::assertSame('client', $request->getClientIdentifier());
        self::assertSame('Claude', $request->getClientName());
        self::assertSame(self::REDIRECT_URI, $request->getRedirectUri());
        self::assertSame('claude.ai', $request->getRedirectHost());
        self::assertSame('mcp:read mcp:write', $request->getScope());
        self::assertSame(self::RESOURCE, $request->getResource());
    }

    #[Test]
    public function createRequestTakesTheOnlyRegisteredRedirectUriIfNoneIsGiven(): void
    {
        $query = $this->getQuery();
        unset($query['redirect_uri']);

        self::assertSame(self::REDIRECT_URI, $this->subject->createRequest($query, self::RESOURCE)->getRedirectUri());
    }

    public static function notRedirectableDataProvider(): array
    {
        return [
            'unknown client' => [['client_id' => 'unknown']],
            'no client' => [['client_id' => '']],
            'unregistered redirect uri' => [['redirect_uri' => 'https://attacker.example.org/callback']],
        ];
    }

    #[Test]
    #[DataProvider('notRedirectableDataProvider')]
    public function errorOfClientOrRedirectUriIsNeverRedirected(array $override): void
    {
        try {
            $this->subject->createRequest(array_replace($this->getQuery(), $override), self::RESOURCE);
            self::fail('No exception was thrown');
        } catch (OAuthRedirectException) {
            self::fail('An error of the client or the redirect uri must not be redirected');
        } catch (OAuthException $exception) {
            self::assertNotSame('', $exception->getError());
        }
    }

    public static function redirectableDataProvider(): array
    {
        return [
            'implicit flow' => [['response_type' => 'token'], OAuthException::UNSUPPORTED_RESPONSE_TYPE],
            'no code challenge' => [['code_challenge' => ''], OAuthException::INVALID_REQUEST],
            'plain code challenge' => [['code_challenge_method' => 'plain'], OAuthException::INVALID_REQUEST],
            'malformed code challenge' => [['code_challenge' => 'short'], OAuthException::INVALID_REQUEST],
            'other resource' => [['resource' => 'https://other.example.org/mcp'], OAuthException::INVALID_TARGET],
            'state too long' => [['state' => str_repeat('s', 1025)], OAuthException::INVALID_REQUEST],
        ];
    }

    #[Test]
    #[DataProvider('redirectableDataProvider')]
    public function errorOfTheRequestIsRedirectedToTheClient(array $override, string $expectedError): void
    {
        try {
            $this->subject->createRequest(array_replace($this->getQuery(), $override), self::RESOURCE);
            self::fail('No exception was thrown');
        } catch (OAuthRedirectException $exception) {
            self::assertSame($expectedError, $exception->getError());
            self::assertSame(self::REDIRECT_URI, $exception->getRedirectUri());
        }
    }

    #[Test]
    public function resourceWithTrailingSlashOrNoResourceIsAccepted(): void
    {
        $withSlash = $this->subject->createRequest(
            ['resource' => self::RESOURCE . '/'] + $this->getQuery(),
            self::RESOURCE
        );
        $query = $this->getQuery();
        unset($query['resource']);

        self::assertSame(self::RESOURCE, $withSlash->getResource());
        self::assertSame(self::RESOURCE, $this->subject->createRequest($query, self::RESOURCE)->getResource());
    }

    #[Test]
    public function storedRequestIsFoundUntilItIsRemoved(): void
    {
        $identifier = $this->subject->storeRequest($this->createRequest('mcp:read'));

        self::assertInstanceOf(AuthorizationRequest::class, $this->subject->findRequest($identifier));
        $this->subject->removeRequest($identifier);
        self::assertNull($this->subject->findRequest($identifier));
        self::assertNull($this->subject->findRequest('../../etc/passwd'));
    }

    #[Test]
    public function approvalRedirectsWithACodeThatIsValidOnce(): void
    {
        $url = $this->subject->getApprovalUrl($this->createRequest('mcp:read mcp:write'), 4, false);

        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
        self::assertStringStartsWith(self::REDIRECT_URI . '?code=', $url);
        self::assertSame('xyz', $parameters['state']);
        self::assertSame(self::RESOURCE, $parameters['iss']);

        $code = $this->subject->consumeCode($parameters['code']);
        self::assertNotNull($code);
        self::assertSame(4, $code->getBackendUserIdentifier());
        self::assertSame('mcp:read mcp:write', $code->getScope());
        self::assertSame(self::CODE_CHALLENGE, $code->getCodeChallenge());
        self::assertNull($this->subject->consumeCode($parameters['code']));
    }

    public static function approvedScopeDataProvider(): array
    {
        return [
            'write requested and allowed' => ['mcp:read mcp:write', false, 'mcp:read mcp:write'],
            'write requested, read chosen' => ['mcp:read mcp:write', true, 'mcp:read'],
            'read requested, write chosen' => ['mcp:read', false, 'mcp:read'],
        ];
    }

    #[Test]
    #[DataProvider('approvedScopeDataProvider')]
    public function approvalGrantsNeverMoreThanRequestedAndChosen(
        string $requestedScope,
        bool $isReadOnly,
        string $expectedScope
    ): void {
        $url = $this->subject->getApprovalUrl($this->createRequest($requestedScope), 4, $isReadOnly);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);

        self::assertSame($expectedScope, $this->subject->consumeCode($parameters['code'])?->getScope());
    }

    #[Test]
    public function denialRedirectsWithAccessDenied(): void
    {
        $url = $this->subject->getDenialUrl($this->createRequest('mcp:read'));

        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);
        self::assertSame('access_denied', $parameters['error']);
        self::assertSame('xyz', $parameters['state']);
        self::assertArrayNotHasKey('code', $parameters);
    }

    #[Test]
    public function getRedirectUrlKeepsTheQueryOfTheRedirectUri(): void
    {
        self::assertSame(
            'https://chatgpt.com/cb?x=1&code=abc',
            $this->subject->getRedirectUrl('https://chatgpt.com/cb?x=1', ['code' => 'abc', 'state' => ''])
        );
    }

    /**
     * @return array<string, string>
     */
    private function getQuery(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => 'client',
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => 'mcp:read mcp:write',
            'resource' => self::RESOURCE,
        ];
    }

    private function createRequest(string $scope): AuthorizationRequest
    {
        return new AuthorizationRequest(
            'client',
            'Claude',
            self::REDIRECT_URI,
            self::CODE_CHALLENGE,
            'xyz',
            $scope,
            self::RESOURCE
        );
    }
}
