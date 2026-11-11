<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\FieldItemService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Domain\Service\TceFormService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\ItemProcessingService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FieldItemServiceTest extends UnitTestCase
{
    private const ITEMS = [
        ['value' => 0, 'label' => 'Above, center'],
        ['value' => 8, 'label' => 'Below, center'],
        ['value' => 17, 'label' => 'In text, right'],
        ['value' => 25, 'label' => 'Beside text, right'],
    ];

    private FieldItemService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $tcaService = $this->createMock(TcaService::class);
        $tcaService->method('translateLabel')->willReturnCallback(
            static fn(string $label): string => 'translated:' . $label
        );

        $this->subject = new FieldItemService(
            $tcaService,
            $this->createMock(TceFormService::class),
            $this->createMock(BackendUserService::class),
            $this->createStub(ItemProcessingService::class)
        );
    }

    #[Test]
    public function applyPageTsConfigKeepsItemsWithoutConfiguration(): void
    {
        self::assertSame(self::ITEMS, $this->subject->applyPageTsConfig(self::ITEMS, []));
    }

    #[Test]
    public function applyPageTsConfigKeepsOnlyListedItems(): void
    {
        $items = $this->subject->applyPageTsConfig(self::ITEMS, ['keepItems' => '0, 25']);

        self::assertSame([0, 25], array_column($items, 'value'));
    }

    #[Test]
    public function applyPageTsConfigRemovesEveryItemForEmptyKeepItems(): void
    {
        self::assertSame([], $this->subject->applyPageTsConfig(self::ITEMS, ['keepItems' => '']));
    }

    #[Test]
    public function applyPageTsConfigRemovesListedItems(): void
    {
        $items = $this->subject->applyPageTsConfig(self::ITEMS, ['removeItems' => '8,17']);

        self::assertSame([0, 25], array_column($items, 'value'));
    }

    #[Test]
    public function applyPageTsConfigAddsItemsWithTypeOfExistingValues(): void
    {
        $items = $this->subject->applyPageTsConfig(self::ITEMS, [
            'addItems.' => ['66' => 'Custom', '66.' => ['icon' => 'custom-icon'], '8' => 'Duplicate'],
        ]);

        self::assertSame([0, 8, 17, 25, 66], array_column($items, 'value'));
        self::assertSame(['value' => 66, 'label' => 'translated:Custom'], $items[4]);
    }

    #[Test]
    public function applyPageTsConfigAddsStringValuesToFieldWithStringValues(): void
    {
        $items = $this->subject->applyPageTsConfig(
            [['value' => 'default', 'label' => 'Default']],
            ['addItems.' => ['7' => 'Seven']]
        );

        self::assertSame(['default', '7'], array_column($items, 'value'));
    }

    #[Test]
    public function applyPageTsConfigAddedItemSurvivesKeepItemsButNotRemoveItems(): void
    {
        $configuration = ['keepItems' => '0', 'addItems.' => ['66' => 'Custom', '77' => 'Other']];

        $kept = $this->subject->applyPageTsConfig(self::ITEMS, $configuration);
        $removed = $this->subject->applyPageTsConfig(self::ITEMS, $configuration + ['removeItems' => '66']);

        self::assertSame([0, 66, 77], array_column($kept, 'value'));
        self::assertSame([0, 77], array_column($removed, 'value'));
    }

    #[Test]
    public function applyPageTsConfigReplacesLabelsByAltLabels(): void
    {
        $items = $this->subject->applyPageTsConfig(self::ITEMS, ['altLabels.' => ['8' => 'Below', '17' => '']]);

        self::assertSame('translated:Below', $items[1]['label']);
        self::assertSame('In text, right', $items[2]['label']);
    }

    #[Test]
    public function applyPageTsConfigReturnsList(): void
    {
        $items = $this->subject->applyPageTsConfig(self::ITEMS, ['removeItems' => '0']);

        self::assertTrue(array_is_list($items));
    }
}
