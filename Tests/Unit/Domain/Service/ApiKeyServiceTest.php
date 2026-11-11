<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ApiKeyServiceTest extends UnitTestCase
{
    private BackendUserRepository&MockObject $backendUserRepository;
    private ApiKeyService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backendUserRepository = $this->createMock(BackendUserRepository::class);

        $hashInstance = $this->createMock(PasswordHashInterface::class);
        $hashInstance->method('getHashedPassword')->willReturnCallback(
            static fn(string $secret): string => 'hash:' . $secret
        );
        $passwordHashFactory = $this->createMock(PasswordHashFactory::class);
        $passwordHashFactory->method('getDefaultHashInstance')->with('BE')->willReturn($hashInstance);

        $this->subject = new ApiKeyService($this->backendUserRepository, $passwordHashFactory);
    }

    #[Test]
    public function createApiKeyStoresOnlyHashOfSecretAndReturnsKeyWithUserUid(): void
    {
        $this->backendUserRepository->method('findByUidOrUsername')->with('editor')->willReturn(['uid' => 7]);
        $storedHash = null;
        $this->backendUserRepository->expects(self::once())
            ->method('updateApiKey')
            ->willReturnCallback(static function (int $uid, string $hash) use (&$storedHash): void {
                self::assertSame(7, $uid);
                $storedHash = $hash;
            });

        $apiKey = $this->subject->createApiKey('editor');

        [$uid, $secret] = explode(BackendUserRepository::API_KEY_SEPARATOR, $apiKey, 2);
        self::assertSame('7', $uid);
        self::assertSame('hash:' . $secret, $storedHash);
    }

    #[Test]
    public function createApiKeyReturnsUrlSafeSecretOfSufficientLength(): void
    {
        $this->backendUserRepository->method('findByUidOrUsername')->willReturn(['uid' => 1]);

        $secret = explode(BackendUserRepository::API_KEY_SEPARATOR, $this->subject->createApiKey('1'), 2)[1];

        self::assertMatchesRegularExpression('~^[A-Za-z0-9_-]{128}$~', $secret);
    }

    #[Test]
    public function createApiKeyReturnsDifferentKeyOnEveryCall(): void
    {
        $this->backendUserRepository->method('findByUidOrUsername')->willReturn(['uid' => 1]);

        self::assertNotSame($this->subject->createApiKey('1'), $this->subject->createApiKey('1'));
    }

    #[Test]
    public function createApiKeyThrowsForUnknownUser(): void
    {
        $this->backendUserRepository->method('findByUidOrUsername')->willReturn(null);
        $this->backendUserRepository->expects(self::never())->method('updateApiKey');

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionCode(1756800600);
        $this->subject->createApiKey('nobody');
    }

    #[Test]
    public function revokeApiKeyEmptiesStoredHash(): void
    {
        $this->backendUserRepository->method('findByUidOrUsername')->willReturn(['uid' => 3]);
        $this->backendUserRepository->expects(self::once())->method('updateApiKey')->with(3, '');

        $this->subject->revokeApiKey('3');
    }
}
