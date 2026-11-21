<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

readonly class OAuthTokens
{
    private const TOKEN_TYPE = 'Bearer';

    public function __construct(
        private string $accessToken,
        private string $refreshToken,
        private int $expiresIn,
        private string $scope,
    ) {
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    public function toResponse(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => self::TOKEN_TYPE,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
        ];
    }
}
