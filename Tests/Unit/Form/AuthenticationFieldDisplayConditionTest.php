<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Form;

use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Form\AuthenticationFieldDisplayCondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AuthenticationFieldDisplayConditionTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ConfigurationService::EXTENSION_KEY]);
        parent::tearDown();
    }

    public static function switchesDataProvider(): array
    {
        return [
            'both on' => ['1', '1', true, true],
            'api keys only' => ['1', '0', true, false],
            'OAuth only' => ['0', '1', false, true],
            'both off' => ['0', '0', false, false],
        ];
    }

    #[Test]
    #[DataProvider('switchesDataProvider')]
    public function fieldsFollowTheirSwitches(
        string $apiKeyAuthentication,
        string $oauth,
        bool $isApiKeyFieldShown,
        bool $isOAuthFieldShown
    ): void {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][ConfigurationService::EXTENSION_KEY] = [
            'apiKeyAuthentication' => $apiKeyAuthentication,
            'oauth' => $oauth,
        ];
        $subject = new AuthenticationFieldDisplayCondition(new ConfigurationService(new ExtensionConfiguration()));

        self::assertSame($isApiKeyFieldShown, $subject->isApiKeyAuthenticationActivated());
        self::assertSame($isOAuthFieldShown, $subject->isOAuthActivated());
    }
}
