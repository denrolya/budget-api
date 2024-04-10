<?php

namespace App\Command;

use App\Entity\Expense;
use App\Entity\Transaction;
use App\Service\FixerService;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fix:recalculate-transaction-values',
    description: 'This command was used to recalculate transaction values. It is not needed anymore. Some transactions were stored without BTC value, expenses with compensations now contain value of expenses minus compensations.',
    hidden: true,
)]
class RecalculateTransactionValuesCommand extends Command
{
    private EntityManagerInterface $em;

    private FixerService $fixerService;

    public function __construct(EntityManagerInterface $entityManager, FixerService $fixerService)
    {
        $this->em = $entityManager;
        $this->fixerService = $fixerService;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // find all transactions in ascending order by id
        $transactions = $this->em->getRepository(Transaction::class)->findBy([], ['id' => 'ASC']);

        $transactionsCount = count($transactions);
        foreach($transactions as $index => $transaction) {
            if ($transaction->getCurrency() === 'ETH') {
                continue;
            }
            $io->info(sprintf('[%d/%d]: Recalculating transaction #%d', $index, $transactionsCount, $transaction->getId()));
            $oldValue = $transaction->getValue();
            $this->recalculateTransactionValue($transaction);
            $newValue = $transaction->getValue();
            $io->info(sprintf('Transaction #%d recalculated: %s -> %s', $transaction->getId(), $oldValue, $newValue));
        }

        $io->success('All transactions recalculated successfully!');

        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    private function recalculateTransactionValue(&$transaction): void
    {
        $transactionValue = $this->fixerService->convert(
            amount: $transaction->getAmount(),
            fromCurrency: $transaction->getCurrency(),
            executionDate: $transaction->getExecutedAt()
        );

        if($transaction->isExpense() && $transaction->hasCompensations()) {
            foreach($transaction->getCompensations() as $compensation) {
                if ($compensation->getCurrency() === 'ETH') {
                    continue;
                }
                $compensation->setConvertedValues(
                    $this->fixerService->convert(
                        amount: $compensation->getAmount(),
                        fromCurrency: $compensation->getCurrency(),
                        executionDate: $compensation->getExecutedAt()
                    )
                );
                $currencies = array_keys($transactionValue);
                foreach($currencies as $currency) {
                    $transactionValue[$currency] -= $compensation->getConvertedValue($currency);
                }
            }
        }

        $transaction->setConvertedValues($transactionValue);

        // Use Doctrine's connection to execute plain SQL
        $conn = $this->em->getConnection();
        $sql = 'UPDATE transaction SET converted_values = :convertedValues WHERE id = :id';
        $stmt = $conn->prepare($sql);
        $stmt->executeQuery(['convertedValues' => json_encode($transactionValue, JSON_THROW_ON_ERROR), 'id' => $transaction->getId()]);
    }
}
