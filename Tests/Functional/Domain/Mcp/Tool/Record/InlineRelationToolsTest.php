<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

final class InlineRelationToolsTest extends AbstractFunctionalTestCase
{
    private const NEWS_IN_MOUNT = 1;
    private const NEWS_OUTSIDE_OF_MOUNT = 2;
    private const LINK_OF_FOREIGN_NEWS = 1;
    private const REFERENCE_OUTSIDE_OF_MOUNT = 1;
    private const REFERENCE_OF_PAGE = 2;
    private const REFERENCE_IN_IMAGE_FIELD = 3;
    private const CONTENT_WITH_IMAGE = 10;
    private const CONTENT_OUTSIDE_OF_MOUNT = 11;
    private const MISSING_UID = 999;

    protected array $testExtensionsToLoad = ['in2code/in2mcp', 'georgringer/news'];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/in2mcp/Tests/Functional/Fixtures/Files/fileadmin/' => 'fileadmin/',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/Files.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/References.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/News.csv');
    }

    #[Test]
    public function addChildRecordCreatesALinkOfANews(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_IN_MOUNT,
            'parentField' => 'related_links',
            'fields' => ['title' => 'Link', 'uri' => 'https://example.org'],
        ]);

        self::assertSame('tx_news_domain_model_link', $result['table']);
        $link = $this->getRow('tx_news_domain_model_link', $result['uid']);
        self::assertSame(self::NEWS_IN_MOUNT, (int)$link['parent']);
        self::assertSame(1, (int)$this->getRow('tx_news_domain_model_news', self::NEWS_IN_MOUNT)['related_links']);
    }

    #[Test]
    public function addChildRecordCreatesAContentElementOfANews(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_IN_MOUNT,
            'parentField' => 'content_elements',
            'fields' => ['CType' => 'text', 'header' => 'Content of the news'],
        ]);

        $contentElement = $this->getRow('tt_content', $result['uid']);
        self::assertSame(self::NEWS_IN_MOUNT, (int)$contentElement['tx_news_related_news']);
        self::assertSame(2, (int)$contentElement['pid']);
    }

    #[Test]
    public function addChildRecordRefusesAForeignChildInsideOfTheNewChild(): void
    {
        $this->loginAs(self::EDITOR);

        try {
            $this->callTool('add_child_record', [
                'parentTable' => 'tx_news_domain_model_news',
                'parentUid' => self::NEWS_IN_MOUNT,
                'parentField' => 'content_elements',
                'fields' => [
                    'CType' => 'textmedia',
                    'assets' => (string)self::REFERENCE_OUTSIDE_OF_MOUNT,
                ],
            ]);
            self::fail('The reference of another element was accepted');
        } catch (ClientFacingException) {
        }

        $this->assertReferenceOwner(self::REFERENCE_OUTSIDE_OF_MOUNT, 'tt_content', self::CONTENT_OUTSIDE_OF_MOUNT);
        self::assertSame(0, (int)$this->getRow('tx_news_domain_model_news', self::NEWS_IN_MOUNT)['content_elements']);
    }

    #[Test]
    public function addChildRecordRefusesAFileField(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('add_child_record', [
            'parentTable' => 'tt_content',
            'parentUid' => 1,
            'parentField' => 'assets',
            'fields' => ['title' => 'Not a file'],
        ]);
    }

    #[Test]
    public function addChildRecordRefusesAParentOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('add_child_record', [
            'parentTable' => 'tx_news_domain_model_news',
            'parentUid' => self::NEWS_OUTSIDE_OF_MOUNT,
            'parentField' => 'related_links',
            'fields' => ['title' => 'Link', 'uri' => 'https://example.org'],
        ]);
    }

    #[Test]
    public function addChildRecordAnswersAMissingAndAnInaccessibleParentAlike(): void
    {
        $this->loginAs(self::EDITOR);
        $arguments = [
            'parentTable' => 'tx_news_domain_model_news',
            'parentField' => 'related_links',
            'fields' => ['title' => 'Link', 'uri' => 'https://example.org'],
        ];

        self::assertSame(
            $this->getMessage('add_child_record', $arguments + ['parentUid' => self::MISSING_UID]),
            $this->getMessage('add_child_record', $arguments + ['parentUid' => self::NEWS_OUTSIDE_OF_MOUNT])
        );
    }

    #[Test]
    public function addFileReferenceAnswersAMissingAndAnInaccessibleRecordAlike(): void
    {
        $this->loginAs(self::EDITOR);
        $arguments = ['fileUid' => 1, 'table' => 'tt_content', 'fieldName' => 'assets'];

        self::assertSame(
            $this->getMessage('add_file_reference', $arguments + ['uid' => self::MISSING_UID]),
            $this->getMessage('add_file_reference', $arguments + ['uid' => self::CONTENT_OUTSIDE_OF_MOUNT])
        );
    }

    #[Test]
    public function updateRecordRefusesTheChildOfAnotherParent(): void
    {
        $this->loginAs(self::EDITOR);

        try {
            $this->callTool('update_record', [
                'table' => 'tx_news_domain_model_news',
                'uid' => self::NEWS_IN_MOUNT,
                'fields' => ['related_links' => (string)self::LINK_OF_FOREIGN_NEWS],
            ]);
            self::fail('The link of another news was accepted');
        } catch (ClientFacingException) {
        }

        self::assertSame(
            self::NEWS_OUTSIDE_OF_MOUNT,
            (int)$this->getRow('tx_news_domain_model_link', self::LINK_OF_FOREIGN_NEWS)['parent']
        );
    }

    #[Test]
    public function updateContentElementRefusesTheFileReferenceOfAnotherElement(): void
    {
        $this->loginAs(self::EDITOR);

        try {
            $this->callTool(
                'update_content_element',
                ['contentUid' => 1, 'fields' => ['assets' => (string)self::REFERENCE_OUTSIDE_OF_MOUNT]]
            );
            self::fail('The file reference of another element was accepted');
        } catch (ClientFacingException) {
        }

        $this->assertReferenceOwner(self::REFERENCE_OUTSIDE_OF_MOUNT, 'tt_content', self::CONTENT_OUTSIDE_OF_MOUNT);
    }

    #[Test]
    public function updateContentElementRefusesTheFileReferenceOfAPageWithTheSameUid(): void
    {
        $this->loginAs(self::EDITOR);

        try {
            $this->callTool(
                'update_content_element',
                ['contentUid' => 2, 'fields' => ['assets' => (string)self::REFERENCE_OF_PAGE]]
            );
            self::fail('The file reference of the page with the same uid was accepted');
        } catch (ClientFacingException) {
        }

        $this->assertReferenceOwner(self::REFERENCE_OF_PAGE, 'pages', 2);
    }

    #[Test]
    public function updateContentElementRefusesTheFileReferenceOfAnotherFieldOfTheSameElement(): void
    {
        $this->loginAs(self::EDITOR);

        try {
            $this->callTool(
                'update_content_element',
                [
                    'contentUid' => self::CONTENT_WITH_IMAGE,
                    'fields' => ['assets' => (string)self::REFERENCE_IN_IMAGE_FIELD],
                ]
            );
            self::fail('The file reference of the image field was accepted for the assets field');
        } catch (ClientFacingException) {
        }

        self::assertSame('image', $this->getRow('sys_file_reference', self::REFERENCE_IN_IMAGE_FIELD)['fieldname']);
    }

    #[Test]
    public function updateContentElementKeepsItsOwnFileReferences(): void
    {
        $this->loginAs(self::EDITOR);

        $this->callTool(
            'update_content_element',
            [
                'contentUid' => self::CONTENT_WITH_IMAGE,
                'fields' => ['header' => 'Changed', 'image' => (string)self::REFERENCE_IN_IMAGE_FIELD],
            ]
        );

        $this->assertReferenceOwner(self::REFERENCE_IN_IMAGE_FIELD, 'tt_content', self::CONTENT_WITH_IMAGE);
    }

    #[Test]
    public function fileFieldListsOnlyTheReferencesOfItsOwnTableAndField(): void
    {
        $this->loginAs(self::ADMIN);

        $contentElement = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 2])['record'];
        $page = $this->callTool('get_record', ['table' => 'pages', 'uid' => 2])['record'];
        $imageElement = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => self::CONTENT_WITH_IMAGE]);

        self::assertSame([], $this->getUids($contentElement['assets'] ?? ''));
        self::assertSame([self::REFERENCE_OF_PAGE], $this->getUids($page['media'] ?? ''));
        self::assertSame([self::REFERENCE_IN_IMAGE_FIELD], $this->getUids($imageElement['record']['image'] ?? ''));
        self::assertSame([], $this->getUids($imageElement['record']['assets'] ?? ''));
    }

    #[Test]
    public function fileReferenceOfAPageIsStoredOnThePageItself(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'add_file_reference',
            ['fileUid' => 1, 'table' => 'pages', 'uid' => 3, 'fieldName' => 'media']
        );

        self::assertSame(3, (int)$this->getRow('sys_file_reference', $result['fileReferenceUid'])['pid']);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function getMessage(string $toolName, array $arguments): string
    {
        try {
            $this->callTool($toolName, $arguments);
        } catch (ClientFacingException $exception) {
            return str_replace(
                [
                    (string)self::MISSING_UID,
                    (string)self::NEWS_OUTSIDE_OF_MOUNT,
                    (string)self::CONTENT_OUTSIDE_OF_MOUNT,
                ],
                '<uid>',
                $exception->getMessage()
            );
        }
        self::fail('Tool "' . $toolName . '" did not refuse the record');
    }

    private function assertReferenceOwner(int $referenceUid, string $table, int $uid): void
    {
        $reference = $this->getRow('sys_file_reference', $referenceUid);
        self::assertSame($table, $reference['tablenames']);
        self::assertSame($uid, (int)$reference['uid_foreign']);
    }

    /**
     * @return list<int>
     */
    private function getUids(mixed $value): array
    {
        $uids = is_array($value) ? $value : explode(',', (string)$value);
        return array_values(array_map('intval', array_filter($uids, static fn(mixed $uid): bool => (int)$uid > 0)));
    }

    /**
     * @return array<string, mixed>
     */
    private function getRow(string $table, int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'Record ' . $table . ':' . $uid . ' does not exist');
        return $row;
    }
}
