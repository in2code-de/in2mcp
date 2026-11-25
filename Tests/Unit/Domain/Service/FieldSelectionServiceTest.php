<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\FieldSelectionService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\ToolExecutionException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FieldSelectionServiceTest extends UnitTestCase
{
    private const RECORD = [
        'uid' => 3,
        'pid' => 1,
        'header' => 'Header',
        'bodytext' => 'Text',
        'pi_flexform' => '<xml/>',
    ];

    #[Test]
    public function selectFieldsKeepsUidPidAndTheSelectedFields(): void
    {
        self::assertSame(
            ['uid' => 3, 'pid' => 1, 'header' => 'Header'],
            $this->createSubject()->selectFields(self::RECORD, ['header'])
        );
    }

    #[Test]
    public function selectFieldsWithoutASelectionReturnsTheWholeRecord(): void
    {
        self::assertSame(self::RECORD, $this->createSubject()->selectFields(self::RECORD, []));
        self::assertNull($this->createSubject()->selectFields(null, ['header']));
    }

    #[Test]
    public function getSelectedFieldsAcceptsFieldsOfTheTableAndUid(): void
    {
        self::assertSame(['header', 'uid'], $this->createSubject()->getSelectedFields('tt_content', ['header', 'uid']));
    }

    #[Test]
    public function getSelectedFieldsRefusesAnUnknownField(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->expectExceptionCode(1764080100);
        $this->createSubject()->getSelectedFields('tt_content', ['header', 'no_such_field']);
    }

    #[Test]
    public function getSelectedFieldsRefusesAValueThatIsNoFieldName(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->createSubject()->getSelectedFields('tt_content', [['header']]);
    }

    private function createSubject(): FieldSelectionService
    {
        $tcaService = self::createStub(TcaService::class);
        $tcaService->method('isFieldOfTable')->willReturnCallback(
            static fn (string $table, string $fieldName): bool => in_array($fieldName, ['header', 'bodytext'], true)
        );
        return new FieldSelectionService($tcaService);
    }
}
