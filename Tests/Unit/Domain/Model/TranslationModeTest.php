<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Model;

use In2code\In2mcp\Domain\Model\TranslationMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationModeTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: list<int>, 1: TranslationMode}>
     */
    public static function translationParentsDataProvider(): array
    {
        return [
            'no content' => [[], TranslationMode::Empty],
            'every element points to its original' => [[4, 7], TranslationMode::Connected],
            'no element points to an original' => [[0, 0], TranslationMode::Free],
            'both' => [[4, 0], TranslationMode::Mixed],
        ];
    }

    /**
     * @param list<int> $translationParentUids
     */
    #[Test]
    #[DataProvider('translationParentsDataProvider')]
    public function fromTranslationParentsJudgesTheContentOfALanguage(
        array $translationParentUids,
        TranslationMode $expected
    ): void {
        self::assertSame($expected, TranslationMode::fromTranslationParents($translationParentUids));
    }
}
