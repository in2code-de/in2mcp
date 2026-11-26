<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Page;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ResolveUrlToolTest extends AbstractFunctionalTestCase
{
    private const BASE_URL = 'https://typo3-testing.local';

    protected array $coreExtensionsToLoad = ['redirects'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/UrlResolution.csv');
    }

    #[Test]
    public function resolveUrlFindsThePageOfASlug(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->resolve('/about');

        self::assertSame('page', $result['status']);
        self::assertSame(2, $result['page']['pageUid']);
        self::assertSame('About', $result['page']['title']);
        self::assertSame(0, $result['language']['languageId']);
        self::assertNull($result['redirect']);
    }

    #[Test]
    public function resolveUrlReportsAPageTheEditorMayNotSeeAsMissing(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->resolve('/secret');

        self::assertSame('not_found', $result['status']);
        self::assertArrayNotHasKey('pageUid', $result['page']);
    }

    #[Test]
    public function resolveUrlFindsAPageTheEditorMayNotSeeForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        self::assertSame(5, $this->resolve('/secret')['page']['pageUid']);
    }

    #[Test]
    public function resolveUrlExplainsWhyAHiddenPageIsNotDelivered(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->resolve('/hidden-page');

        self::assertSame('unavailable', $result['status']);
        self::assertSame(['The page is hidden'], $result['page']['reasonsForMissingOutput']);
    }

    #[Test]
    public function resolveUrlExplainsThatAFolderIsNotRendered(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->resolve('/folder');

        self::assertSame('unavailable', $result['status']);
        self::assertStringContainsString('are not rendered', $result['page']['reasonsForMissingOutput'][0]);
    }

    #[Test]
    public function resolveUrlFindsTheTranslationOfAPage(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->resolve('/de/neuigkeiten');

        self::assertSame('page', $result['status']);
        self::assertSame(7, $result['page']['pageUid']);
        self::assertSame(8, $result['page']['recordUid']);
        self::assertSame('strict', $result['language']['fallbackType']);
    }

    #[Test]
    public function resolveUrlHidesATranslationOfALanguageTheEditorMayNotUse(): void
    {
        $this->loginAs(self::EDITOR);

        self::assertSame('not_found', $this->resolve('/de/neuigkeiten')['status']);
    }

    #[Test]
    public function resolveUrlPointsToThePageOfTheDefaultLanguageWithThisSlug(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->resolve('/de/only-default');

        self::assertSame('not_found', $result['status']);
        self::assertSame(9, $result['page']['pageOfDefaultLanguage']);
    }

    #[Test]
    public function resolveUrlReportsARedirectBeforeThePage(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->resolve('/old-page');

        self::assertSame('redirect', $result['status']);
        self::assertSame('t3://page?uid=2', $result['redirect']['target']);
        self::assertSame(301, $result['redirect']['statusCode']);
        self::assertSame(1, $result['redirect']['redirectUid']);
        self::assertTrue($result['redirect']['targetLink']['exists']);
    }

    #[Test]
    public function resolveUrlLeavesOutTheUidOfARedirectForAnEditorWithoutTheTable(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->resolve('/old-page');

        self::assertSame('redirect', $result['status']);
        self::assertArrayNotHasKey('redirectUid', $result['redirect']);
    }

    #[Test]
    public function resolveUrlFindsNoSiteForAnUnknownHost(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('resolve_url', ['url' => 'https://unknown.example.org/about']);

        self::assertSame('not_found', $result['status']);
        self::assertNull($result['site']);
    }

    #[Test]
    public function resolveUrlRefusesAUrlWithoutHost(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('resolve_url', ['url' => '/about']);
    }

    private function resolve(string $path): array
    {
        return $this->callTool('resolve_url', ['url' => self::BASE_URL . $path]);
    }
}
