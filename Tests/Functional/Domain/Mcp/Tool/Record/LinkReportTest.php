<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class LinkReportTest extends AbstractFunctionalTestCase
{
    private const NEWS_IN_MOUNT = 1;
    private const CONTENT_IN_MOUNT = 1;

    protected array $testExtensionsToLoad = ['in2code/in2mcp', 'georgringer/news'];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/in2mcp/Tests/Functional/Fixtures/Files/fileadmin/' => 'fileadmin/',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/Files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/News.csv');
    }

    public static function linksOfAnEditorDataProvider(): array
    {
        return [
            'page in the mount' => ['t3://page?uid=2', 'page', true],
            'page outside of the mount' => ['t3://page?uid=4', 'page', false],
            'page without read permission' => ['t3://page?uid=5', 'page', false],
            'missing page' => ['t3://page?uid=999', 'page', false],
            'file in the file mount' => ['t3://file?uid=1', 'file', true],
            'file outside of the file mount' => ['t3://file?uid=2', 'file', false],
            'missing file' => ['t3://file?uid=999999', 'file', false],
            'typolink with target and title' => ['t3://page?uid=2 _blank - "About us"', 'page', true],
        ];
    }

    #[Test]
    #[DataProvider('linksOfAnEditorDataProvider')]
    public function addChildRecordReportsWhetherTheTargetOfALinkExists(
        string $link,
        string $expectedType,
        bool $expectedExistence
    ): void {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_IN_MOUNT,
            'parentField' => 'related_links',
            'fields' => ['title' => 'Link', 'uri' => $link],
        ]);

        self::assertSame($expectedType, $result['links']['uri']['type']);
        self::assertSame($expectedExistence, $result['links']['uri']['exists']);
    }

    #[Test]
    public function aPageLinkDescribesItsTarget(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_IN_MOUNT,
            'parentField' => 'related_links',
            'fields' => ['uri' => 't3://page?uid=2'],
        ]);

        self::assertSame(['pageUid' => 2, 'title' => 'About', 'hidden' => false], $result['links']['uri']['target']);
    }

    #[Test]
    public function aFileLinkResolvesToItsUrl(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_IN_MOUNT,
            'parentField' => 'related_links',
            'fields' => ['uri' => 't3://file?uid=1'],
        ]);

        self::assertSame('/editor/image.png', $result['links']['uri']['target']['identifier']);
        self::assertStringEndsWith('fileadmin/editor/image.png', $result['links']['uri']['target']['url']);
    }

    #[Test]
    public function anExternalUrlIsNotRequested(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_IN_MOUNT,
            'parentField' => 'related_links',
            'fields' => ['uri' => 'https://example.org/missing'],
        ]);

        self::assertSame('url', $result['links']['uri']['type']);
        self::assertArrayNotHasKey('exists', $result['links']['uri']);
    }

    #[Test]
    public function anAdministratorSeesAPageOutsideOfTheMountOfTheEditor(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool(
            'update_content_element',
            ['contentUid' => self::CONTENT_IN_MOUNT, 'fields' => ['header_link' => 't3://page?uid=4']]
        );

        self::assertTrue($result['links']['header_link']['exists']);
        self::assertSame('Outside of the mount', $result['links']['header_link']['target']['title']);
    }

    #[Test]
    public function createRecordReportsTheLinksOfTheNewRecord(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('create_record', [
            'table' => 'tx_news_domain_model_link',
            'pid' => 2,
            'fields' => ['title' => 'Link', 'uri' => 't3://page?uid=999'],
        ]);

        self::assertFalse($result['links']['uri']['exists']);
    }

    #[Test]
    public function aWriteWithoutLinkFieldsReportsNoLinks(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'update_content_element',
            ['contentUid' => self::CONTENT_IN_MOUNT, 'fields' => ['header' => 'No link']]
        );

        self::assertArrayNotHasKey('links', $result);
    }
}
