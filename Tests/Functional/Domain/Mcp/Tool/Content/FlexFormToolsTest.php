<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Content;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FlexFormToolsTest extends AbstractFunctionalTestCase
{
    private const NEWS_LIST = 'news_pi1';

    protected array $testExtensionsToLoad = ['in2code/in2mcp', 'georgringer/news'];

    #[Test]
    public function singleFlexFormFieldIsChangedAndTheOthersAreKept(): void
    {
        $this->loginAs(self::ADMIN);
        $created = $this->callTool(
            'create_content_element',
            [
                'pageUid' => 2,
                'contentType' => self::NEWS_LIST,
                'fields' => [
                    'pi_flexform' => ['sDEF' => ['settings.orderBy' => 'title', 'settings.orderDirection' => 'asc']],
                ],
            ]
        );

        $updated = $this->callTool(
            'update_content_element',
            [
                'contentUid' => $created['contentUid'],
                'fields' => ['pi_flexform' => ['sDEF' => ['settings.orderDirection' => 'desc']]],
            ]
        );

        self::assertSame(
            ['settings.orderBy' => 'title', 'settings.orderDirection' => 'desc'],
            $this->getSheetValues($updated['contentElement']['pi_flexform'], 'sDEF')
        );
    }

    #[Test]
    public function completeFlexFormStructureIsMergedAsWell(): void
    {
        $this->loginAs(self::ADMIN);
        $created = $this->callTool(
            'create_content_element',
            [
                'pageUid' => 2,
                'contentType' => self::NEWS_LIST,
                'fields' => ['pi_flexform' => ['sDEF' => ['settings.orderBy' => 'title']]],
            ]
        );
        $limit = ['additional' => ['lDEF' => ['settings.limit' => ['vDEF' => '5']]]];

        $updated = $this->callTool(
            'update_record',
            [
                'table' => 'tt_content',
                'uid' => $created['contentUid'],
                'fields' => ['pi_flexform' => ['data' => $limit]],
            ]
        );

        $flexForm = $updated['record']['pi_flexform'];
        self::assertSame('title', $this->getSheetValues($flexForm, 'sDEF')['settings.orderBy']);
        self::assertSame('5', $this->getSheetValues($flexForm, 'additional')['settings.limit']);
    }

    #[Test]
    public function flexFormShortFormRefusesASheetWithoutFields(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->expectExceptionCode(1764081300);
        $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => self::NEWS_LIST, 'fields' => ['pi_flexform' => ['sDEF' => 'title']]]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getSheetValues(string $flexForm, string $sheet): array
    {
        $fields = GeneralUtility::xml2array($flexForm)['data'][$sheet]['lDEF'] ?? [];
        return array_map(static fn (array $field): mixed => $field['vDEF'] ?? null, $fields);
    }
}
