<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Model;

use In2code\In2mcp\Domain\Model\AccessScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AccessScopeTest extends UnitTestCase
{
    public static function scopeStringDataProvider(): array
    {
        return [
            'read' => ['mcp:read', 'mcp:read', true],
            'write includes read' => ['mcp:write', 'mcp:read mcp:write', false],
            'both' => ['mcp:read mcp:write', 'mcp:read mcp:write', false],
            'no scope means full access' => ['', 'mcp:read mcp:write', false],
            'unknown scope only means full access' => ['openid profile', 'mcp:read mcp:write', false],
            'unknown scope is dropped' => ['openid mcp:read', 'mcp:read', true],
            'surrounding spaces' => ['  mcp:read  ', 'mcp:read', true],
            'case matters' => ['MCP:READ', 'mcp:read mcp:write', false],
        ];
    }

    #[Test]
    #[DataProvider('scopeStringDataProvider')]
    public function fromScopeStringNormalizesTheScopes(string $scope, string $expected, bool $isReadOnly): void
    {
        $scopes = AccessScope::fromScopeString($scope);

        self::assertSame($expected, AccessScope::toScopeString($scopes));
        self::assertSame($isReadOnly, AccessScope::isReadOnly($scopes));
    }
}
