<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp;

use In2code\In2mcp\Domain\Mcp\Converter\McpToolSchemaConverter;
use In2code\In2mcp\Domain\Mcp\Executer\ToolExecuter;
use In2code\In2mcp\Domain\Mcp\Tool\ToolRegistry;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class ServerFactory
{
    protected const SERVER_NAME = 'TYPO3 in2mcp';
    protected const EXTENSION_KEY = 'in2mcp';
    protected const SESSION_DIRECTORY = 'in2mcp/mcp';
    protected const SESSION_TIME_TO_LIVE = 3600;
    protected const INSTRUCTIONS = 'This server gives access to the content of a TYPO3 installation. Use the'
        . ' tools to read the page tree and the content of pages, and to create or change pages and content'
        . ' elements. All actions run with the permissions of the connected TYPO3 backend user, so a denied'
        . ' action means that this user is not allowed to perform it. Call "get_backend_user" first to learn'
        . ' about these permissions. Before creating new content, look at comparable existing pages to follow'
        . ' the structure and the wording of the installation.';

    public function __construct(
        protected readonly ToolRegistry $toolRegistry,
        protected readonly McpToolSchemaConverter $toolSchemaConverter,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function get(): Server
    {
        $builder = Server::builder()
            ->setServerInfo(
                self::SERVER_NAME,
                ExtensionManagementUtility::getExtensionVersion(self::EXTENSION_KEY)
            )
            ->setInstructions(self::INSTRUCTIONS)
            ->setLogger($this->logger)
            ->setSession(new FileSessionStore($this->getSessionDirectory(), self::SESSION_TIME_TO_LIVE));

        foreach ($this->toolRegistry->getTools() as $tool) {
            $builder->add($this->toolSchemaConverter->convert($tool), new ToolExecuter($tool));
        }

        return $builder->build();
    }

    /**
     * MCP sessions must be available in every request of a client, so they are stored in the filesystem
     */
    protected function getSessionDirectory(): string
    {
        return Environment::getVarPath() . '/' . self::SESSION_DIRECTORY;
    }
}
