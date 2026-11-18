<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Model\UploadToken;
use In2code\In2mcp\Domain\Service\UploadTokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UploadTokenServiceTest extends UnitTestCase
{
    // The cache backend fetches a logger through the LogManager singleton
    protected bool $resetSingletonInstances = true;

    private VariableFrontend $cache;
    private UploadTokenService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new VariableFrontend('in2mcp_upload_test', new TransientMemoryBackend());
        $this->subject = new UploadTokenService($this->cache, new Random());
    }

    #[Test]
    public function consumeReturnsTheDataOfTheToken(): void
    {
        $token = $this->subject->create(4, '1:/user_upload/', 'photo.jpg');

        $uploadToken = $this->subject->consume($token);

        self::assertInstanceOf(UploadToken::class, $uploadToken);
        self::assertSame(4, $uploadToken->getUserIdentifier());
        self::assertSame('1:/user_upload/', $uploadToken->getFolder());
        self::assertSame('photo.jpg', $uploadToken->getFileName());
    }

    #[Test]
    public function tokenIsValidOnlyOnce(): void
    {
        $token = $this->subject->create(4, '1:/user_upload/', '');
        $this->subject->consume($token);

        self::assertNull($this->subject->consume($token));
    }

    #[Test]
    public function tokensAreUnique(): void
    {
        self::assertNotSame(
            $this->subject->create(4, '1:/', ''),
            $this->subject->create(4, '1:/', '')
        );
    }

    #[Test]
    public function tokenIsNotStoredInPlainText(): void
    {
        $token = $this->subject->create(4, '1:/user_upload/', '');

        self::assertFalse($this->cache->has($token));
    }

    #[Test]
    public function expiredTokenIsRefused(): void
    {
        $token = str_repeat('a', 64);
        $this->cache->set(
            hash('sha256', $token),
            (new UploadToken(4, '1:/user_upload/', '', time() - 1))->toArray()
        );

        self::assertNull($this->subject->consume($token));
    }

    public static function invalidTokenDataProvider(): array
    {
        return [
            'empty' => [''],
            'unknown' => [str_repeat('b', 64)],
            'too short' => ['abc'],
            'upper case' => [str_repeat('A', 64)],
            'not hexadecimal' => [str_repeat('z', 64)],
        ];
    }

    #[Test]
    #[DataProvider('invalidTokenDataProvider')]
    public function invalidTokenIsRefused(string $token): void
    {
        self::assertNull($this->subject->consume($token));
    }

    #[Test]
    public function tokenWithoutUserIsRefused(): void
    {
        $token = str_repeat('c', 64);
        $this->cache->set(hash('sha256', $token), ['folder' => '1:/', 'expires' => time() + 60]);

        self::assertNull($this->subject->consume($token));
    }
}
