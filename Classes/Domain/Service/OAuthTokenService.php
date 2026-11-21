<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\OAuthGrant;
use In2code\In2mcp\Domain\Model\OAuthTokens;
use In2code\In2mcp\Domain\Repository\OAuthGrantRepository;
use TYPO3\CMS\Core\Crypto\Random;

readonly class OAuthTokenService
{
    // The prefixes keep a token apart from an api key "<uid>.<secret>", which always starts with a digit
    public const ACCESS_TOKEN_PREFIX = 'mcpat.';
    public const REFRESH_TOKEN_PREFIX = 'mcprt.';

    public const ACCESS_TOKEN_LIFETIME = 3600;
    public const REFRESH_TOKEN_LIFETIME = 2592000;

    private const SECRET_LENGTH = 64;
    private const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private OAuthGrantRepository $grantRepository,
        private Random $random,
    ) {
    }

    /**
     * @throws Exception
     */
    public function issue(
        int $backendUserIdentifier,
        string $clientIdentifier,
        string $scope,
        string $resource
    ): OAuthTokens {
        $now = time();
        $accessToken = $this->createToken(self::ACCESS_TOKEN_PREFIX);
        $refreshToken = $this->createToken(self::REFRESH_TOKEN_PREFIX);

        $this->grantRepository->insert([
            'be_user' => $backendUserIdentifier,
            'client_id' => $clientIdentifier,
            'scope' => $scope,
            'resource' => $resource,
            'access_token_hash' => $this->hash($accessToken),
            'access_token_expires' => $now + self::ACCESS_TOKEN_LIFETIME,
            'refresh_token_hash' => $this->hash($refreshToken),
            'refresh_token_expires' => $now + self::REFRESH_TOKEN_LIFETIME,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return new OAuthTokens($accessToken, $refreshToken, self::ACCESS_TOKEN_LIFETIME, $scope);
    }

    /**
     * A token is bound to the endpoint it was issued for, so it is worthless on another installation or path
     *
     * @throws Exception
     */
    public function findGrantByAccessToken(string $accessToken, string $resource): ?OAuthGrant
    {
        $grant = null;
        if (self::isWellFormedAccessToken($accessToken)) {
            $grant = $this->grantRepository->findByValidAccessTokenHash($this->hash($accessToken), time());
        }
        return $grant !== null && hash_equals($grant->getResource(), $resource) ? $grant : null;
    }

    public static function isAccessToken(string $credential): bool
    {
        return str_starts_with($credential, self::ACCESS_TOKEN_PREFIX);
    }

    public static function isWellFormedAccessToken(string $credential): bool
    {
        $pattern = '~^' . preg_quote(self::ACCESS_TOKEN_PREFIX, '~') . '[0-9a-f]{' . self::SECRET_LENGTH . '}$~';
        return preg_match($pattern, $credential) === 1;
    }

    private function createToken(string $prefix): string
    {
        return $prefix . $this->random->generateRandomHexString(self::SECRET_LENGTH);
    }

    // A random token of 256 bits needs no slow password hash, a plain hash makes it searchable in the database
    private function hash(string $token): string
    {
        return hash(self::HASH_ALGORITHM, $token);
    }
}
