<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\EmptyFieldService;
use In2code\In2mcp\Domain\Service\TcaService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class EmptyFieldServiceTest extends UnitTestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>, bool, string|int}>
     */
    public static function emptyValueDataProvider(): array
    {
        return [
            'text' => ['text', [], false, ''],
            'input' => ['input', [], false, ''],
            'link' => ['link', [], false, ''],
            'number' => ['number', [], false, 0],
            'checkbox' => ['check', [], false, 0],
            'timestamp' => ['datetime', [], false, 0],
            'file field counting its references' => ['file', [], true, 0],
            'inline field counting its children' => ['inline', [], true, 0],
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[Test]
    #[DataProvider('emptyValueDataProvider')]
    public function emptyValueFollowsTheTypeOfTheField(
        string $type,
        array $configuration,
        bool $isCountingChildren,
        string|int $expected
    ): void {
        self::assertSame(
            $expected,
            $this->createSubject($type, $configuration, $isCountingChildren)->getEmptyValue('pages', 'field')
        );
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function unsupportedFieldDataProvider(): array
    {
        return [
            'select with string or integer values' => ['select', []],
            'native datetime column' => ['datetime', ['dbType' => 'datetime']],
            'inline field holding a list of uids' => ['inline', []],
        ];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[Test]
    #[DataProvider('unsupportedFieldDataProvider')]
    public function fieldWithoutAKnownEmptyValueIsRefused(string $type, array $configuration): void
    {
        $this->expectExceptionCode(1795478401);
        $this->createSubject($type, $configuration, false)->getEmptyValue('pages', 'field');
    }

    #[Test]
    public function unknownFieldIsRefused(): void
    {
        $tcaService = self::createStub(TcaService::class);
        $tcaService->method('getFieldDefinition')->willReturn(null);

        $this->expectExceptionCode(1795478401);
        (new EmptyFieldService($tcaService))->getEmptyValue('pages', 'field');
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createSubject(string $type, array $configuration, bool $isCountingChildren): EmptyFieldService
    {
        $field = self::createStub(FieldTypeInterface::class);
        $field->method('getType')->willReturn($type);
        $field->method('getName')->willReturn('field');
        $field->method('getConfiguration')->willReturn($configuration);

        $tcaService = self::createStub(TcaService::class);
        $tcaService->method('getFieldDefinition')->willReturn($field);
        $tcaService->method('getInlineRelation')->willReturn(
            $isCountingChildren ? ['foreignTable' => 'child', 'foreignField' => 'parent'] : null
        );
        return new EmptyFieldService($tcaService);
    }
}
