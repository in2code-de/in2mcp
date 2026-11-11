<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ApiKeyServiceTest extends UnitTestCase
{
    private const BACKEND_HASH_MODE = 'BE';

    #[Test]
    public function createApiKeyStoresOnlyBackendHashOfSecretAndReturnsKeyWithUserUid(): void
    {
        $backendUserRepository = $this->createMock(BackendUserRepository::class);
        $backendUserRepository->expects(self::once())
            ->method('findByUidOrUsername')
            ->with('editor')
            ->willReturn(['uid' => 7]);
        $storedHash = null;
        $backendUserRepository->expects(self::once())
            ->method('updateApiKey')
            ->willReturnCallback(static function (int $uid, string $hash) use (&$storedHash): void {
                self::assertSame(7, $uid);
                $storedHash = $hash;
            });

        $apiKey = $this->createSubject($backendUserRepository)->createApiKey('editor');

        [$uid, $secret] = explode(BackendUserRepository::API_KEY_SEPARATOR, $apiKey, 2);
        self::assertSame('7', $uid);
        self::assertSame(self::BACKEND_HASH_MODE . ':' . $secret, $storedHash);
    }

    #[Test]
    public function createApiKeyReturnsUrlSafeSecretOfSufficientLength(): void
    {
        $subject = $this->createSubject($this->createRepositoryFindingUser(1));

        $secret = explode(BackendUserRepository::API_KEY_SEPARATOR, $subject->createApiKey('1'), 2)[1];

        self::assertMatchesRegularExpression('~^[A-Za-z0-9_-]{128}$~', $secret);
    }

    #[Test]
    public function createApiKeyReturnsDifferentKeyOnEveryCall(): void
    {
        $subject = $this->createSubject($this->createRepositoryFindingUser(1));

        self::assertNotSame($subject->createApiKey('1'), $subject->createApiKey('1'));
    }

    #[Test]
    public function createApiKeyThrowsForUnknownUser(): void
    {
        $backendUserRepository = $this->createMock(BackendUserRepository::class);
        $backendUserRepository->expects(self::once())->method('findByUidOrUsername')->willReturn(null);
        $backendUserRepository->expects(self::never())->method('updateApiKey');

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionCode(1756800600);
        $this->createSubject($backendUserRepository)->createApiKey('nobody');
    }

    #[Test]
    public function revokeApiKeyEmptiesStoredHash(): void
    {
        $backendUserRepository = $this->createMock(BackendUserRepository::class);
        $backendUserRepository->expects(self::once())->method('findByUidOrUsername')->willReturn(['uid' => 3]);
        $backendUserRepository->expects(self::once())->method('updateApiKey')->with(3, '');

        $this->createSubject($backendUserRepository)->revokeApiKey('3');
    }

    private function createRepositoryFindingUser(int $uid): BackendUserRepository
    {
        $backendUserRepository = $this->createStub(BackendUserRepository::class);
        $backendUserRepository->method('findByUidOrUsername')->willReturn(['uid' => $uid]);
        return $backendUserRepository;
    }

    /**
     * The hash is "<mode>:<secret>", so a test sees both which hash mode was used and what was hashed
     */
    private function createSubject(BackendUserRepository $backendUserRepository): ApiKeyService
    {
        $passwordHashFactory = $this->createStub(PasswordHashFactory::class);
        $passwordHashFactory->method('getDefaultHashInstance')->willReturnCallback(
            fn(string $mode): PasswordHashInterface => $this->createHashInstance($mode)
        );
        return new ApiKeyService($backendUserRepository, $passwordHashFactory);
    }

    private function createHashInstance(string $mode): PasswordHashInterface
    {
        $hashInstance = $this->createStub(PasswordHashInterface::class);
        $hashInstance->method('getHashedPassword')->willReturnCallback(
            static fn(string $secret): string => $mode . ':' . $secret
        );
        return $hashInstance;
    }
}
