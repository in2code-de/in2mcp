<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Model\UploadToken;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\Random;

class UploadTokenService
{
    public const CACHE_IDENTIFIER = 'in2mcp_upload';
    public const LIFETIME = 900;

    private const TOKEN_LENGTH = 64;
    private const TOKEN_PATTERN = '~^[0-9a-f]{64}$~';
    private const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private readonly FrontendInterface $uploadTokenCache,
        private readonly Random $random,
    ) {
    }

    public function create(int $userIdentifier, string $folder, string $fileName): string
    {
        $token = $this->random->generateRandomHexString(self::TOKEN_LENGTH);
        $uploadToken = new UploadToken($userIdentifier, $folder, $fileName, time() + self::LIFETIME);
        // Only the hash is stored, so the cache content alone does not allow an upload
        $this->uploadTokenCache->set($this->getCacheEntry($token), $uploadToken->toArray(), [], self::LIFETIME);
        return $token;
    }

    /**
     * A token is valid exactly once: it is removed as soon as it is read, whether the upload succeeds or not
     */
    public function consume(string $token): ?UploadToken
    {
        $uploadToken = null;

        if (preg_match(self::TOKEN_PATTERN, $token) === 1) {
            $cacheEntry = $this->getCacheEntry($token);
            $data = $this->uploadTokenCache->get($cacheEntry);
            $this->uploadTokenCache->remove($cacheEntry);

            if (is_array($data)) {
                $uploadToken = UploadToken::fromArray($data);
            }
        }

        return $uploadToken?->isValidAt(time()) ? $uploadToken : null;
    }

    private function getCacheEntry(string $token): string
    {
        return hash(self::HASH_ALGORITHM, $token);
    }
}
