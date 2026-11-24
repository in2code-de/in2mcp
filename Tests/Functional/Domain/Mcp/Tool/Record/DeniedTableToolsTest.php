<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\Record;

use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

final class DeniedTableToolsTest extends AbstractFunctionalTestCase
{
    private const CONFIGURED_DENIAL_CODE = 1795509483;
    private const NEWS_IN_MOUNT = 1;
    private const LINK_ARGUMENTS = [
        'parentTable' => 'tx_news_domain_model_news',
        'parentUid' => self::NEWS_IN_MOUNT,
        'parentField' => 'related_links',
        'fields' => ['title' => 'Link', 'uri' => 'https://example.org'],
    ];

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

    protected function tearDown(): void
    {
        putenv(ConfigurationService::DENIED_TABLES_ENVIRONMENT_VARIABLE);
        parent::tearDown();
    }

    #[Test]
    public function updateRecordRefusesADeniedTable(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('update_record', ['table' => 'tt_content', 'uid' => 1, 'fields' => ['header' => 'x']]);
    }

    #[Test]
    public function updateContentElementRefusesADeniedTable(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::EDITOR);

        $this->expectConfiguredDenial();
        $this->callTool('update_content_element', ['contentUid' => 1, 'fields' => ['header' => 'x']]);
    }

    #[Test]
    public function deleteRecordRefusesADeniedTable(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('delete_record', ['table' => 'tt_content', 'uid' => 2]);
    }

    #[Test]
    public function moveRecordRefusesADeniedTable(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('move_record', ['table' => 'tt_content', 'uid' => 1, 'afterRecordUid' => 2]);
    }

    #[Test]
    public function localizeRecordRefusesADeniedTable(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('localize_record', ['table' => 'tt_content', 'uid' => 1, 'languageId' => 1]);
    }

    #[Test]
    public function addChildRecordRefusesADeniedChildTable(): void
    {
        $this->denyTables('tx_news_domain_model_li*');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('add_child_record', self::LINK_ARGUMENTS);
    }

    #[Test]
    public function addChildRecordRefusesADeniedParentTable(): void
    {
        $this->denyTables('tx_news_domain_model_news');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('add_child_record', self::LINK_ARGUMENTS);
    }

    #[Test]
    public function addFileReferenceRefusesADeniedTable(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool(
            'add_file_reference',
            ['fileUid' => 1, 'table' => 'tt_content', 'uid' => 1, 'fieldName' => 'assets']
        );
    }

    #[Test]
    public function environmentVariableDeniesATableWhileTheSettingIsEmpty(): void
    {
        putenv(ConfigurationService::DENIED_TABLES_ENVIRONMENT_VARIABLE . '=tt_content');
        $this->denyTables('');
        $this->loginAs(self::ADMIN);

        $this->expectConfiguredDenial();
        $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1]);
    }

    #[Test]
    public function settingOverridesTheEnvironmentVariable(): void
    {
        putenv(ConfigurationService::DENIED_TABLES_ENVIRONMENT_VARIABLE . '=tt_content');
        $this->denyTables('tx_news_domain_model_news');
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => 1]);

        self::assertSame('First element', $result['record']['header']);
    }

    #[Test]
    public function deletingAPageDeletesTheRecordsOfADeniedTableOnIt(): void
    {
        $this->denyTables('tt_content');
        $this->loginAs(self::ADMIN);

        $this->callTool('delete_record', ['table' => 'pages', 'uid' => 2]);

        self::assertSame(1, (int)$this->getRow('tt_content', 1)['deleted']);
    }

    private function denyTables(string $tables): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['in2mcp']['deniedTables'] = $tables;
    }

    private function expectConfiguredDenial(): void
    {
        $this->expectException(ClientFacingException::class);
        $this->expectExceptionCode(self::CONFIGURED_DENIAL_CODE);
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
