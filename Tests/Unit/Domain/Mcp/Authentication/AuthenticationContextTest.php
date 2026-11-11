<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Mcp\Authentication;

use In2code\In2mcp\Domain\Mcp\Authentication\AuthenticationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AuthenticationContextTest extends UnitTestCase
{
    public static function apiKeyHeaderDataProvider(): array
    {
        return [
            'bearer token' => [['Authorization' => 'Bearer 1.secret'], '1.secret'],
            'bearer scheme case insensitive' => [['Authorization' => 'bearer 1.secret'], '1.secret'],
            'bearer token with surrounding spaces' => [['Authorization' => '  Bearer   1.secret  '], '1.secret'],
            'x-api-key header' => [['X-Api-Key' => '1.secret'], '1.secret'],
            'api-key header' => [['Api-Key' => '1.secret'], '1.secret'],
            'authorization wins over x-api-key' => [
                ['Authorization' => 'Bearer 1.bearer', 'X-Api-Key' => '1.header'],
                '1.bearer',
            ],
            'x-api-key wins over api-key' => [['X-Api-Key' => '1.first', 'Api-Key' => '1.second'], '1.first'],
            'other authorization scheme falls back to header' => [
                ['Authorization' => 'Basic dXNlcjpwYXNz', 'Api-Key' => '1.secret'],
                '1.secret',
            ],
            'other authorization scheme only' => [['Authorization' => 'Basic dXNlcjpwYXNz'], ''],
            'scheme without separator is no bearer token' => [['Authorization' => 'Bearer1.secret'], ''],
            'no header' => [[], ''],
            'empty header' => [['X-Api-Key' => '   '], ''],
        ];
    }

    #[Test]
    #[DataProvider('apiKeyHeaderDataProvider')]
    public function fromRequestReadsApiKeyFromHeaders(array $headers, string $expectedApiKey): void
    {
        $request = new ServerRequest('https://example.org/typo3/mcp', 'POST', 'php://temp', $headers);

        $context = AuthenticationContext::fromRequest($request);

        self::assertSame($expectedApiKey, $context->getApiKey());
        self::assertSame($expectedApiKey !== '', $context->hasApiKey());
    }

    #[Test]
    public function fromRequestAttributeReturnsContextOfRequest(): void
    {
        $context = new AuthenticationContext('1.secret');
        $request = (new ServerRequest())->withAttribute(AuthenticationContext::REQUEST_ATTRIBUTE, $context);

        self::assertSame($context, AuthenticationContext::fromRequestAttribute($request));
    }

    #[Test]
    public function fromRequestAttributeReturnsNullForOtherRequests(): void
    {
        self::assertNull(AuthenticationContext::fromRequestAttribute(new ServerRequest()));
        self::assertNull(AuthenticationContext::fromRequestAttribute(
            (new ServerRequest())->withAttribute(AuthenticationContext::REQUEST_ATTRIBUTE, 'forged')
        ));
    }

    #[Test]
    public function isAuthenticatedUserIsFalseAsLongAsNoUserIsRecorded(): void
    {
        $context = new AuthenticationContext('1.secret');

        self::assertNull($context->getAuthenticatedUserIdentifier());
        self::assertFalse($context->isAuthenticatedUser(0));
        self::assertFalse($context->isAuthenticatedUser(1));
    }

    #[Test]
    public function isAuthenticatedUserComparesRecordedUser(): void
    {
        $context = new AuthenticationContext('1.secret');
        $context->setAuthenticatedUserIdentifier(1);

        self::assertSame(1, $context->getAuthenticatedUserIdentifier());
        self::assertTrue($context->isAuthenticatedUser(1));
        self::assertFalse($context->isAuthenticatedUser(2));
    }
}
