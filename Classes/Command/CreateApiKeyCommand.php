<?php

declare(strict_types=1);

namespace In2code\In2mcp\Command;

use In2code\In2mcp\Domain\Service\ApiKeyService;
use In2code\In2mcp\Middleware\McpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'in2mcp:apikey',
    description: 'Create or revoke the MCP api key of a backend user'
)]
class CreateApiKeyCommand extends Command
{
    public function __construct(private readonly ApiKeyService $apiKeyService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'backendUser',
            InputArgument::REQUIRED,
            'Uid or username of the backend user'
        );
        $this->addOption(
            'revoke',
            'r',
            InputOption::VALUE_NONE,
            'Remove the api key of the backend user instead of creating a new one'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $backendUserIdentifier = (string)$input->getArgument('backendUser');

        try {
            if ($input->getOption('revoke')) {
                $this->apiKeyService->revokeApiKey($backendUserIdentifier);
                $io->success('Api key of backend user "' . $backendUserIdentifier . '" was revoked');
                return Command::SUCCESS;
            }

            $backendUser = $this->apiKeyService->getBackendUser($backendUserIdentifier);
            $apiKey = $this->apiKeyService->createApiKey($backendUserIdentifier);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success('Api key created for backend user "' . $backendUser['username'] . '"');
        $io->writeln('This key is shown only once and is stored as a hash only:');
        $io->writeln('');
        $output->writeln($apiKey);
        $io->writeln('');
        $io->writeln('Use it as a request header against ' . McpServer::MCP_SERVER_PATH . ':');
        $io->listing([
            'Authorization: Bearer <key>',
            'X-Api-Key: <key>',
            'Api-Key: <key>',
        ]);

        return Command::SUCCESS;
    }
}
