<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\DenormalizedIdentifiersAwareItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Account;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Repository\TransactionRepository;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TODO: Perhaps this thing can use query parameters to specify from/to date to get categories statistics
 */
final class AccountItemProvider implements DenormalizedIdentifiersAwareItemDataProviderInterface, RestrictedDataProviderInterface
{
    private TransactionRepository $transactionRepo;

    public function __construct(
        private StatisticsManager         $statisticsManager,
        private ItemDataProviderInterface $itemDataProvider,
        EntityManagerInterface            $em
    )
    {
        $this->transactionRepo = $em->getRepository(Transaction::class);
    }

    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): ?object
    {
        /** @var Account $account */
        $account = $this->itemDataProvider->getItem($resourceClass, $id, $operationName, $context);

        if(!$account) {
            return null;
        }
        $startOfDecade = (new CarbonImmutable())->startOfDecade();
        $endOfDecade = (new CarbonImmutable())->endOfDecade();

        $expenses = $this->transactionRepo->getList($startOfDecade, $endOfDecade, TransactionInterface::EXPENSE, null, [$account]);
        $incomes = $this->transactionRepo->getList($startOfDecade, $endOfDecade, TransactionInterface::INCOME, null, [$account]);

        $lastTransactionAt = $this->transactionRepo->findOneBy([
            'account' => $account,
            'canceledAt' => null,
        ], [
            'executedAt' => 'DESC'
        ]);

        $account
            ->setLastTransactionAt($lastTransactionAt?->getExecutedAt())
            ->setTopExpenseCategories(
                $this->statisticsManager->generateCategoryTreeStatisticsWithinPeriod(
                    TransactionInterface::EXPENSE,
                    $expenses
                )
            )
            ->setTopIncomeCategories(
                $this->statisticsManager->generateCategoryTreeStatisticsWithinPeriod(
                    TransactionInterface::INCOME,
                    $incomes
                )
            );

        return $account;
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Account::class && (array_key_exists('groups', $context) && $context['groups'] === 'account:item:read');
    }
}
