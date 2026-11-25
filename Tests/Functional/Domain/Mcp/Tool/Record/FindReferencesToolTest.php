<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FindReferencesToolTest extends AbstractFunctionalTestCase
{
    private const LINK_TO_CONTACT = '<p><a href="t3://page?uid=3">Contact</a></p>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
        foreach ([1, 3] as $contentUid) {
            $this->callTool(
                'update_content_element',
                ['contentUid' => $contentUid, 'fields' => ['bodytext' => self::LINK_TO_CONTACT]]
            );
        }
    }

    #[Test]
    public function findReferencesListsLinksInTextsForAnAdministrator(): void
    {
        $result = $this->callTool('find_references', ['table' => 'pages', 'uid' => 3]);

        self::assertSame([['tt_content', 1], ['tt_content', 3]], $this->getRecords($result['references']));
        self::assertSame('bodytext', $result['references'][0]['field']);
        self::assertNull($result['nextOffset']);
    }

    #[Test]
    public function findReferencesLeavesOutRecordsOutsideOfTheMountOfAnEditor(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('find_references', ['table' => 'pages', 'uid' => 3]);

        self::assertSame([['tt_content', 1]], $this->getRecords($result['references']));
    }

    #[Test]
    public function findReferencesListsTheTranslationsOfAPage(): void
    {
        $translation = $this->callTool('localize_record', ['table' => 'pages', 'uid' => 2, 'languageId' => 1]);

        $result = $this->callTool('find_references', ['table' => 'pages', 'uid' => 2]);

        self::assertContains(
            ['pages', (int)$translation['record']['uid']],
            $this->getRecords($result['references'])
        );
    }

    #[Test]
    public function findReferencesPagesThroughTheReferences(): void
    {
        $first = $this->callTool('find_references', ['table' => 'pages', 'uid' => 3, 'limit' => 1]);
        $second = $this->callTool(
            'find_references',
            ['table' => 'pages', 'uid' => 3, 'limit' => 1, 'offset' => $first['nextOffset']]
        );

        self::assertSame([['tt_content', 1]], $this->getRecords($first['references']));
        self::assertSame([['tt_content', 3]], $this->getRecords($second['references']));
    }

    #[Test]
    public function linksToADeletedPageAreFoundWithFindRecordsInstead(): void
    {
        $this->callTool('delete_record', ['table' => 'pages', 'uid' => 3]);

        $references = $this->callTool('find_references', ['table' => 'pages', 'uid' => 3]);
        $links = $this->callTool(
            'find_records',
            ['table' => 'tt_content', 'filters' => ['bodytext' => ['contains' => 't3://page?uid=3"']]]
        );

        self::assertSame([], $references['references']);
        self::assertSame([1, 3], array_map('intval', array_column($links['records'], 'uid')));
    }

    #[Test]
    public function findReferencesRefusesADeniedTable(): void
    {
        $this->expectException(ClientFacingException::class);
        $this->callTool('find_references', ['table' => 'be_users', 'uid' => 1]);
    }

    /**
     * @param list<array<string, mixed>> $references
     * @return list<array{0: string, 1: int}>
     */
    private function getRecords(array $references): array
    {
        return array_map(
            static fn (array $reference): array => [(string)$reference['table'], (int)$reference['uid']],
            $references
        );
    }
}
