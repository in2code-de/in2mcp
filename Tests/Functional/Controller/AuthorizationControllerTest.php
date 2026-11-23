<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Controller;

use In2code\In2mcp\Controller\AuthorizationController;
use In2code\In2mcp\Domain\Model\AuthorizationCode;
use In2code\In2mcp\Domain\Service\AuthorizationService;
use In2code\In2mcp\Domain\Service\ClientRegistrationService;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

final class AuthorizationControllerTest extends AbstractFunctionalTestCase
{
    private const CONSENT_URL = 'https://typo3-testing.local/typo3/in2mcp/authorize';
    private const RESOURCE = 'https://typo3-testing.local/typo3/mcp';
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
    private const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const CLIENT_NAME = 'Claude <b>Test</b>';
    private const STATE = 'state-123';

    private string $clientIdentifier = '';

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['oauth'] = '1';
        parent::setUp();
        $this->clientIdentifier = $this->get(ClientRegistrationService::class)->register([
            'client_name' => self::CLIENT_NAME,
            'redirect_uris' => [self::REDIRECT_URI],
        ])['client_id'];
    }

    #[Test]
    public function consentPageNamesClientAndUserWithEscapedClientName(): void
    {
        $this->loginAs(self::EDITOR);

        $response = $this->show($this->storeRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        self::assertStringContainsString('Claude &lt;b&gt;Test&lt;/b&gt;', $body);
        self::assertStringNotContainsString('<b>Test</b>', $body);
        self::assertStringContainsString('&quot;editor&quot;', $body);
        self::assertStringContainsString('name="formToken"', $body);
        self::assertStringContainsString('value="allow"', $body);
    }

    #[Test]
    public function consentPageOfAnUnknownRequestOffersNoDecision(): void
    {
        $this->loginAs(self::EDITOR);

        $body = (string)$this->show(str_repeat('a', 64))->getBody();

        self::assertStringNotContainsString('value="allow"', $body);
    }

    #[Test]
    public function consentPageOffersNoApprovalToAUserWithoutMcpAccess(): void
    {
        $this->loginAs(self::EDITOR_WITHOUT_MCP);

        $body = (string)$this->show($this->storeRequest())->getBody();

        self::assertStringNotContainsString('value="allow"', $body);
        self::assertStringContainsString('value="deny"', $body);
    }

    #[Test]
    public function approvalRedirectsWithACodeForTheLoggedInUser(): void
    {
        $this->loginAs(self::EDITOR);
        $identifier = $this->storeRequest();

        $response = $this->decide($identifier, $this->getFormToken($identifier), 'allow', 'write');

        $code = $this->consumeCodeOf($response);
        self::assertSame(self::EDITOR, $code->getBackendUserIdentifier());
        self::assertSame($this->clientIdentifier, $code->getClientIdentifier());
        self::assertSame('mcp:read mcp:write', $code->getScope());
        self::assertSame(self::RESOURCE, $code->getResource());
        self::assertNull($this->get(AuthorizationService::class)->findRequest($identifier));
    }

    #[Test]
    public function approvalForReadingOnlyIssuesAReadOnlyCode(): void
    {
        $this->loginAs(self::EDITOR);
        $identifier = $this->storeRequest();

        $response = $this->decide($identifier, $this->getFormToken($identifier), 'allow', 'read');

        self::assertSame('mcp:read', $this->consumeCodeOf($response)->getScope());
    }

    #[Test]
    public function denialRedirectsWithAccessDenied(): void
    {
        $this->loginAs(self::EDITOR);
        $identifier = $this->storeRequest();

        $parameters = $this->getRedirectParameters(
            $this->decide($identifier, $this->getFormToken($identifier), 'deny', 'write')
        );

        self::assertSame('access_denied', $parameters['error']);
        self::assertSame(self::STATE, $parameters['state']);
        self::assertArrayNotHasKey('code', $parameters);
    }

    #[Test]
    public function approvalOfAUserWithoutMcpAccessIsTurnedIntoADenial(): void
    {
        $this->loginAs(self::EDITOR_WITHOUT_MCP);
        $identifier = $this->storeRequest();

        $parameters = $this->getRedirectParameters(
            $this->decide($identifier, $this->getFormToken($identifier), 'allow', 'write')
        );

        self::assertSame('access_denied', $parameters['error']);
        self::assertArrayNotHasKey('code', $parameters);
    }

    #[Test]
    public function decisionWithoutValidFormTokenIsRefusedAndKeepsTheRequest(): void
    {
        $this->loginAs(self::EDITOR);
        $identifier = $this->storeRequest();

        $response = $this->decide($identifier, 'forged', 'allow', 'write');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertNotNull($this->get(AuthorizationService::class)->findRequest($identifier));
    }

    #[Test]
    public function formTokenOfAnotherRequestIsRefused(): void
    {
        $this->loginAs(self::EDITOR);
        $identifier = $this->storeRequest();
        $formTokenOfOtherRequest = $this->getFormToken($this->storeRequest());

        self::assertSame(400, $this->decide($identifier, $formTokenOfOtherRequest, 'allow', 'write')->getStatusCode());
    }

    #[Test]
    public function requestCanBeDecidedOnlyOnce(): void
    {
        $this->loginAs(self::EDITOR);
        $identifier = $this->storeRequest();
        $formToken = $this->getFormToken($identifier);
        $this->decide($identifier, $formToken, 'allow', 'write');

        self::assertSame(400, $this->decide($identifier, $formToken, 'allow', 'write')->getStatusCode());
    }

    private function storeRequest(): string
    {
        $authorizationService = $this->get(AuthorizationService::class);
        return $authorizationService->storeRequest($authorizationService->createRequest([
            'response_type' => 'code',
            'client_id' => $this->clientIdentifier,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => self::STATE,
            'scope' => 'mcp:read mcp:write',
            'resource' => self::RESOURCE,
        ], self::RESOURCE));
    }

    private function getFormToken(string $identifier): string
    {
        $formProtection = $this->get(FormProtectionFactory::class)->createFromRequest($this->createRequest('GET'));
        $formToken = $formProtection->generateToken('in2mcp', 'authorize', $identifier);
        $formProtection->persistSessionToken();
        return $formToken;
    }

    private function show(string $identifier): ResponseInterface
    {
        $request = $this->createRequest('GET')
            ->withQueryParams([AuthorizationController::REQUEST_PARAMETER => $identifier]);
        return $this->get(AuthorizationController::class)->consentAction($request);
    }

    private function decide(string $identifier, string $formToken, string $decision, string $access): ResponseInterface
    {
        $request = $this->createRequest('POST')->withParsedBody([
            AuthorizationController::REQUEST_PARAMETER => $identifier,
            'formToken' => $formToken,
            'decision' => $decision,
            'access' => $access,
        ]);
        return $this->get(AuthorizationController::class)->consentAction($request);
    }

    private function createRequest(string $method): ServerRequestInterface
    {
        $request = new ServerRequest(self::CONSENT_URL, $method, null, [], [
            'HTTP_HOST' => 'typo3-testing.local',
            'HTTPS' => 'on',
        ]);
        return $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request))
            ->withAttribute('route', $this->get(Router::class)->getRoute(AuthorizationController::ROUTE));
    }

    /**
     * @return array<string, string>
     */
    private function getRedirectParameters(ResponseInterface $response): array
    {
        self::assertSame(303, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith(self::REDIRECT_URI . '?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $parameters);
        self::assertSame(self::RESOURCE, $parameters['iss'] ?? '');
        return $parameters;
    }

    private function consumeCodeOf(ResponseInterface $response): AuthorizationCode
    {
        $parameters = $this->getRedirectParameters($response);
        self::assertSame(self::STATE, $parameters['state']);
        $code = $this->get(AuthorizationService::class)->consumeCode((string)($parameters['code'] ?? ''));
        self::assertNotNull($code);
        return $code;
    }
}
