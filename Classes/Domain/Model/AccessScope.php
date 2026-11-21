<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

enum AccessScope: string
{
    case Read = 'mcp:read';
    case Write = 'mcp:write';

    private const SEPARATOR = ' ';

    /**
     * Unknown scopes are dropped and write includes read. No known scope means full access like an api key, the
     * consent page is where the user narrows it down.
     *
     * @return list<self>
     */
    public static function fromScopeString(string $scope): array
    {
        $scopes = array_values(array_filter(array_map(
            static fn (string $value): ?self => self::tryFrom($value),
            explode(self::SEPARATOR, trim($scope))
        )));

        return $scopes === [] || in_array(self::Write, $scopes, true) ? [self::Read, self::Write] : [self::Read];
    }

    /**
     * @param list<self> $scopes
     */
    public static function toScopeString(array $scopes): string
    {
        return implode(self::SEPARATOR, array_map(static fn (self $scope): string => $scope->value, $scopes));
    }

    /**
     * @param list<self> $scopes
     */
    public static function isReadOnly(array $scopes): bool
    {
        return in_array(self::Write, $scopes, true) === false;
    }
}
