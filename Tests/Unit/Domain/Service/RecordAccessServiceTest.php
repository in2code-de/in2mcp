<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\RecordAccessService;
use In2code\In2mcp\Domain\Service\TcaService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordAccessServiceTest extends UnitTestCase
{
    #[Test]
    public function unrestrictedUserReadsEveryLanguageWithoutAskingTheSchema(): void
    {
        $tcaService = $this->createMock(TcaService::class);
        $tcaService->expects(self::never())->method('getLanguageField');

        $subject = new RecordAccessService($this->createBackendUserService(null), $tcaService);

        self::assertTrue($subject->isRecordReadable('tt_content', ['uid' => 8, 'pid' => 4, 'sys_language_uid' => 1]));
    }

    #[Test]
    public function restrictedUserReadsOnlyAllowedLanguages(): void
    {
        $subject = $this->createRestrictedSubject([0]);

        self::assertTrue($subject->isRecordReadable('tt_content', ['uid' => 3, 'pid' => 4, 'sys_language_uid' => 0]));
        self::assertFalse($subject->isRecordReadable('tt_content', ['uid' => 8, 'pid' => 4, 'sys_language_uid' => 1]));
    }

    #[Test]
    public function restrictedUserReadsPagesOfTheDefaultLanguageLikeThePageTreeShowsThem(): void
    {
        $subject = $this->createRestrictedSubject([1]);

        self::assertTrue($subject->isRecordReadable('pages', ['uid' => 4, 'pid' => 1, 'sys_language_uid' => 0]));
        self::assertFalse($subject->isRecordReadable('tt_content', ['uid' => 3, 'pid' => 4, 'sys_language_uid' => 0]));
    }

    #[Test]
    public function assertLanguageReadableRefusesALanguageOfAnotherEditor(): void
    {
        $this->expectExceptionCode(1756802600);
        $this->createRestrictedSubject([0])->assertLanguageReadable(1);
    }

    /**
     * @param list<int> $allowedLanguageIds
     */
    private function createRestrictedSubject(array $allowedLanguageIds): RecordAccessService
    {
        $tcaService = self::createStub(TcaService::class);
        $tcaService->method('getLanguageField')->willReturn('sys_language_uid');
        return new RecordAccessService($this->createBackendUserService($allowedLanguageIds), $tcaService);
    }

    /**
     * @param list<int>|null $allowedLanguageIds
     */
    private function createBackendUserService(?array $allowedLanguageIds): BackendUserService
    {
        $backendUserService = self::createStub(BackendUserService::class);
        $backendUserService->method('isRecordReachable')->willReturn(true);
        $backendUserService->method('getAllowedLanguageIds')->willReturn($allowedLanguageIds);
        $backendUserService->method('isLanguageAllowed')->willReturnCallback(
            static fn (int $languageId): bool => $allowedLanguageIds === null
                || in_array($languageId, $allowedLanguageIds, true)
        );
        return $backendUserService;
    }
}
