<?php

declare(strict_types=1);

namespace App\Command;

use App\Bank\BankSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Cron-schedulable command for polling-based bank sync.
 *
 * Run all active polling integrations:
 *   bin/console app:bank:sync
 *
 * Run a specific integration:
 *   bin/console app:bank:sync --integration=42
 */
#[AsCommand(name: 'app:bank:sync', description: 'Sync transactions from all active polling-mode bank integrations.')]
class BankSyncCommand extends Command
{
    public function __construct(private readonly BankSyncService $syncService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('integration', 'i', InputOption::VALUE_REQUIRED, 'Sync only this BankIntegration ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawIntegrationId = $input->getOption('integration');
        assert(null === $rawIntegrationId || is_numeric($rawIntegrationId));

        if (null !== $rawIntegrationId) {
            $integrationId = (int) $rawIntegrationId;
            try {
                $created = $this->syncService->syncById($integrationId);
                $io->success(\sprintf('Integration #%d: %d new draft(s) created.', $integrationId, $created));
            } catch (Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $results = $this->syncService->syncAll();
        $total = array_sum($results);

        if ([] === $results) {
            $io->info('No active polling integrations found.');
        } else {
            foreach ($results as $id => $count) {
                $io->writeln(\sprintf('  Integration #%d: %d new draft(s)', $id, $count));
            }
            $io->success(\sprintf('Sync complete. Total new drafts: %d', $total));
        }

        return Command::SUCCESS;
    }
}
