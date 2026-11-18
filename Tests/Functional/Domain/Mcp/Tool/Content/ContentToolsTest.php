<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Content;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContentToolsTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function createContentElementAppendsAtTheEndOfTheColumn(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'fields' => ['header' => 'Third element']]
        );

        self::assertTrue($result['created']);
        self::assertSame([1, 2, $result['contentUid']], $this->getContentUidsOfPage(2));
    }

    #[Test]
    public function createContentElementAtTheStartPutsItInFrontOfTheColumn(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'position' => 'start']
        );

        self::assertSame([$result['contentUid'], 1, 2], $this->getContentUidsOfPage(2));
    }

    #[Test]
    public function createContentElementBehindAnotherElement(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'afterContentUid' => 1]
        );

        self::assertSame([1, $result['contentUid'], 2], $this->getContentUidsOfPage(2));
    }

    #[Test]
    public function createContentElementInAnEmptyColumnOfAPage(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'create_content_element',
            ['pageUid' => 3, 'contentType' => 'text', 'colPos' => 1, 'fields' => ['header' => 'Sidebar']]
        );

        self::assertSame(3, (int)$result['contentElement']['pid']);
        self::assertSame(1, (int)$result['contentElement']['colPos']);
    }

    #[Test]
    public function createContentElementRefusesAContentTypeTheEditorMayNotUse(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('create_content_element', ['pageUid' => 2, 'contentType' => 'html']);
    }

    #[Test]
    public function createContentElementAllowsEveryContentTypeForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'html', 'fields' => ['bodytext' => '<script>raw</script>']]
        );

        self::assertSame('<script>raw</script>', $result['contentElement']['bodytext']);
    }

    #[Test]
    public function createContentElementRefusesAPageOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('create_content_element', ['pageUid' => 4, 'contentType' => 'text']);
    }

    #[Test]
    public function createContentElementRefusesAnUnknownField(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'text', 'fields' => ['does_not_exist' => 'x']]
        );
    }

    #[Test]
    public function updateContentElementWritesTheFields(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'update_content_element',
            ['contentUid' => 1, 'fields' => ['header' => 'Changed', 'bodytext' => '<p>Changed</p>']]
        );

        self::assertSame('Changed', $result['contentElement']['header']);
        self::assertSame('<p>Changed</p>', $result['contentElement']['bodytext']);
    }

    #[Test]
    public function updateContentElementRefusesAContentTypeTheEditorMayNotUse(): void
    {
        $this->loginAs(self::EDITOR);

        $exception = null;
        try {
            $this->callTool('update_content_element', ['contentUid' => 1, 'fields' => ['CType' => 'html']]);
        } catch (ClientFacingException $exception) {
        }

        self::assertInstanceOf(ClientFacingException::class, $exception);
        self::assertStringContainsString('Available types: header, text, textmedia.', $exception->getMessage());
        $contentElement = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1])['record'];
        self::assertSame('text', $contentElement['CType']);
    }

    #[Test]
    public function updateContentElementRefusesAnElementOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('update_content_element', ['contentUid' => 3, 'fields' => ['header' => 'Not allowed']]);
    }

    #[Test]
    public function recentlyChangedContentNamesTheChangedElement(): void
    {
        $this->loginAs(self::EDITOR);
        $this->callTool('update_content_element', ['contentUid' => 2, 'fields' => ['header' => 'Latest']]);

        $result = $this->callTool('get_recently_changed_content', ['pageUid' => 2]);

        self::assertContains('Latest', array_column($result['contentElements'] ?? $result, 'header'));
    }

    /**
     * @return list<int>
     */
    private function getContentUidsOfPage(int $pageUid): array
    {
        $result = $this->callTool('get_page', ['pageUid' => $pageUid]);
        return array_map('intval', array_column($result['content']['colPos_0'] ?? [], 'uid'));
    }
}
