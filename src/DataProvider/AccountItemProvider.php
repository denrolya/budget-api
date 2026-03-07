<?php

namespace App\DataProvider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Service\StatisticsManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Custom state provider for Account GET item that enriches the account
 * with statistics (top expense/income categories).
 *
 * TODO: Perhaps this thing can use query parameters to specify from/to date to get categories statistics
 */
final class AccountItemProvider implements ProviderInterface
{
    private TransactionRepository $transactionRepo;

    public function __construct(
        private StatisticsManager $statisticsManager,
        private EntityManagerInterface $em
    ) {
        $this->transactionRepo = $em->getRepository(Transaction::class);
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var Account|null $account */
        $account = $this->em->getRepository(Account::class)->find($uriVariables['id'] ?? 0);

        if (!$account) {
            return null;
        }

        $expenses = $this->transactionRepo->getList(null, null, Transaction::EXPENSE, null, [$account]);
        $incomes  = $this->transactionRepo->getList(null, null, Transaction::INCOME, null, [$account]);

        $account
            ->setTopExpenseCategories(
                $this->statisticsManager->generateCategoryTreeWithValues(
                    transactions: $expenses,
                    type: Transaction::EXPENSE,
                )
            )
            ->setTopIncomeCategories(
                $this->statisticsManager->generateCategoryTreeWithValues(
                    transactions: $incomes,
                    type: Transaction::INCOME,
                )
            );

        return $account;
    }
}
