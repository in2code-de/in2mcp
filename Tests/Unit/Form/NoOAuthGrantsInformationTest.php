<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Form;

use In2code\In2mcp\Form\NoOAuthGrantsInformation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class NoOAuthGrantsInformationTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturn('No connections <yet>');
        $GLOBALS['LANG'] = $languageService;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    public static function emptyFieldDataProvider(): array
    {
        return [
            'empty list' => [''],
            'zero of a new record' => ['0'],
            'missing value' => [null],
        ];
    }

    #[Test]
    #[DataProvider('emptyFieldDataProvider')]
    public function renderShowsTheEscapedHintWithoutConnections(?string $value): void
    {
        $row = $value === null ? [] : ['in2mcp_oauth_grants' => $value];
        $html = (new NoOAuthGrantsInformation())->render(['field' => 'in2mcp_oauth_grants', 'row' => $row]);
        self::assertSame('<p class="text-body-secondary">No connections &lt;yet&gt;</p>', $html);
    }

    #[Test]
    public function renderShowsNothingWithConnections(): void
    {
        $html = (new NoOAuthGrantsInformation())->render([
            'field' => 'in2mcp_oauth_grants',
            'row' => ['in2mcp_oauth_grants' => '3,7'],
        ]);
        self::assertSame('', $html);
    }
}
