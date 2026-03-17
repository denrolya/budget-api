<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Expense;
use App\Service\AssetsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:fix:restore-expense-gross-values',
    description: 'Recalculates convertedValues for expenses that have compensations, restoring gross (pre-compensation) values. Previously these were stored as net (expense minus compensation), which is wrong — compensations are now subtracted on-the-fly in the statistics layer.',
)]
class RestoreExpenseGrossValuesCommand extends Command
{
    private const BATCH_FLUSH_SIZE = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetsManager $assetsManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Preview changes without writing to the database',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');

        if ($isDryRun) {
            $io->note('DRY RUN — no changes will be written to the database.');
        }

        $expenses = $this->entityManager
            ->getRepository(Expense::class)
            ->findAll();

        $expensesWithCompensations = array_filter(
            $expenses,
            static fn (Expense $expense) => $expense->hasCompensations(),
        );

        $totalCount = \count($expensesWithCompensations);

        if (0 === $totalCount) {
            $io->success('No expenses with compensations found. Nothing to do.');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Found %d expense(s) with compensations to restore.', $totalCount));
        $io->progressStart($totalCount);

        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $index = 0;

        foreach ($expensesWithCompensations as $expense) {
            ++$index;

            if ('ETH' === $expense->getCurrency()) {
                $io->progressAdvance();
                $io->writeln(\sprintf(
                    '  [%d/%d] SKIP expense #%d — ETH not supported by exchange rate service.',
                    $index,
                    $totalCount,
                    $expense->getId(),
                ));
                ++$skippedCount;
                continue;
            }

            try {
                $oldValues = $expense->getConvertedValues();
                $grossValues = $this->assetsManager->convert($expense);

                $compensationCount = $expense->getCompensations()->count();
                $io->writeln(\sprintf(
                    '  [%d/%d] expense #%d | %s %s | %d compensation(s) | EUR: %.2f → %.2f',
                    $index,
                    $totalCount,
                    $expense->getId(),
                    $expense->getAmount(),
                    $expense->getCurrency(),
                    $compensationCount,
                    $oldValues['EUR'] ?? 0.0,
                    $grossValues['EUR'] ?? 0.0,
                ));

                if (!$isDryRun) {
                    $expense->setConvertedValues($grossValues);

                    if (0 === $index % self::BATCH_FLUSH_SIZE) {
                        $this->entityManager->flush();
                        $io->writeln(\sprintf('  Flushed batch at index %d.', $index));
                    }
                }

                ++$processedCount;
            } catch (Throwable $throwable) {
                $io->writeln(\sprintf(
                    '  [%d/%d] ERROR expense #%d — %s',
                    $index,
                    $totalCount,
                    $expense->getId(),
                    $throwable->getMessage(),
                ));
                ++$errorCount;
            }

            $io->progressAdvance();
        }

        if (!$isDryRun && $processedCount > 0) {
            $this->entityManager->flush();
            $io->writeln('  Final flush completed.');
        }

        $io->progressFinish();

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total with compensations', $totalCount],
                ['Processed', $processedCount],
                ['Skipped (ETH)', $skippedCount],
                ['Errors', $errorCount],
            ],
        );

        if ($errorCount > 0) {
            $io->warning(\sprintf('%d expense(s) failed to recalculate. Check the output above.', $errorCount));

            return Command::FAILURE;
        }

        if ($isDryRun) {
            $io->note('Dry run complete. Run without --dry-run to apply changes.');
        } else {
            $io->success(\sprintf('Restored gross values for %d expense(s).', $processedCount));
        }

        return Command::SUCCESS;
    }
}
