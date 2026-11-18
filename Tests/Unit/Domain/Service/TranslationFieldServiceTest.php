<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Domain\Service\TranslationFieldService;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationFieldServiceTest extends UnitTestCase
{
    #[Test]
    public function fieldsThatAreNeitherExcludedNorSynchronizedDoNotReadTheRecord(): void
    {
        $recordRepository = $this->createMock(RecordRepository::class);
        $recordRepository->expects(self::never())->method('findTranslationState');

        (new TranslationFieldService($this->createTcaService(), $recordRepository))
            ->assertWritable('pages', 5, ['title' => 'Title']);
    }

    #[Test]
    public function excludedFieldOfATranslationIsRefused(): void
    {
        $this->expectExceptionCode(1756802704);
        $this->createSubject(4, [])->assertWritable('pages', 5, ['layout' => 2]);
    }

    #[Test]
    public function synchronizedFieldOfATranslationIsRefusedUntilItIsCustom(): void
    {
        $this->expectExceptionCode(1756802708);
        $this->createSubject(4, ['author' => 'parent'])->assertWritable('pages', 5, ['author' => 'Me']);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function synchronizedFieldIsWritableTogetherWithTheCustomState(): void
    {
        $this->createSubject(4, ['author' => 'parent'])
            ->assertWritable('pages', 5, ['author' => 'Me', 'l10n_state' => ['author' => 'custom']]);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function recordInTheDefaultLanguageIsNeverRestricted(): void
    {
        $this->createSubject(0, [])->assertWritable('pages', 4, ['layout' => 2, 'author' => 'Me']);
    }

    #[Test]
    public function stateOfAFieldThatIsNotSynchronizedIsRefused(): void
    {
        $this->expectExceptionCode(1756802700);
        $this->createSubject(4, [])->assertWritable('pages', 5, ['l10n_state' => ['title' => 'custom']]);
    }

    #[Test]
    public function withoutStateFieldRemovesOnlyTheState(): void
    {
        self::assertSame(
            ['author' => 'Me'],
            $this->createSubject(4, [])->withoutStateField(['author' => 'Me', 'l10n_state' => ['author' => 'custom']])
        );
    }

    /**
     * @param array<string, string> $localizationState
     */
    private function createSubject(int $translationParent, array $localizationState): TranslationFieldService
    {
        $recordRepository = self::createStub(RecordRepository::class);
        $recordRepository->method('findTranslationState')->willReturn([
            'translationParent' => $translationParent,
            'localizationState' => $localizationState,
        ]);
        return new TranslationFieldService($this->createTcaService(), $recordRepository);
    }

    private function createTcaService(): TcaService
    {
        $tcaService = self::createStub(TcaService::class);
        $tcaService->method('getTranslationParentField')->willReturn('l10n_parent');
        $tcaService->method('isExcludedFromTranslation')->willReturnCallback(
            static fn (string $table, string $fieldName): bool => $fieldName === 'layout'
        );
        $tcaService->method('isSynchronizedInTranslation')->willReturnCallback(
            static fn (string $table, string $fieldName): bool => in_array($fieldName, ['author', 'media'], true)
        );
        return $tcaService;
    }
}
