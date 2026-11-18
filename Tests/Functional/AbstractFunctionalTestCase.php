<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional;

use In2code\In2mcp\Domain\Mcp\Tool\ToolInterface;
use In2code\In2mcp\Domain\Mcp\Tool\ToolRegistry;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

// The user is initialized like BackendUserAuthenticator does it, only protocol and api key are left out
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected const ADMIN = 1;
    protected const EDITOR = 2;
    protected const EDITOR_WITHOUT_MCP = 3;

    private const ENDPOINT_URL = 'https://typo3-testing.local/typo3/mcp';
    private const ENDPOINT_HOST = 'typo3-testing.local';

    protected array $testExtensionsToLoad = ['in2code/in2mcp'];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/in2mcp/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'in2mcp' => [
                'mcpServer' => '1',
                'mcpServerPath' => 'mcp',
                'mcpServerAllowedIps' => '*',
                'fileImport' => '0',
                'fileImportMaximumSize' => '10485760',
                'fileImportAllowedHosts' => '',
                'fileUpload' => '0',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/BackendUsers.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
    }

    protected function loginAs(int $userUid): void
    {
        $request = new ServerRequest(self::ENDPOINT_URL, 'POST', null, [], ['HTTP_HOST' => self::ENDPOINT_HOST]);
        $request = $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $backendUser = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->get(Context::class)->setAspect('workspace', new WorkspaceAspect(max(0, $backendUser->workspace)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function callTool(string $name, array $arguments = []): mixed
    {
        return $this->getTool($name)->execute($arguments);
    }

    protected function getTool(string $name): ToolInterface
    {
        $tools = array_filter(
            $this->get(ToolRegistry::class)->getTools(),
            static fn (ToolInterface $tool): bool => $tool->getName() === $name
        );
        self::assertCount(1, $tools, 'Tool "' . $name . '" is not registered');
        return reset($tools);
    }
}
