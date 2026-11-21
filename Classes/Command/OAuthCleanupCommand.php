<?php

declare(strict_types=1);

namespace In2code\In2mcp\Command;

use In2code\In2mcp\Domain\Service\OAuthCleanupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'in2mcp:oauth:cleanup',
    description: 'Remove expired OAuth connections and clients that registered themselves but were never used'
)]
class OAuthCleanupCommand extends Command
{
    public function __construct(private readonly OAuthCleanupService $cleanupService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->cleanupService->cleanup(time());
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Removed %d expired connections, %d connections of deleted clients or users and %d unused clients',
            $result['expiredGrants'],
            $result['orphanedGrants'],
            $result['unusedClients']
        ));
        return Command::SUCCESS;
    }
}
