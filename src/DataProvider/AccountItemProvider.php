<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\DenormalizedIdentifiersAwareItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Account;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;

/**
 * TODO: Perhaps this thing can use query parameters to specify from/to date to get categories statistics
 */
final class AccountItemProvider implements DenormalizedIdentifiersAwareItemDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager             $assetsManager,
        private StatisticsManager         $statisticsManager,
        private ItemDataProviderInterface $itemDataProvider
    )
    {
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

        $expenses = $this->assetsManager->generateExpenseTransactionList($startOfDecade, $endOfDecade, null, [$account]);
        $incomes = $this->assetsManager->generateIncomeTransactionList($startOfDecade, $endOfDecade, null, [$account]);

        $account->setTopExpenseCategories(
            $this->statisticsManager->generateCategoryTreeStatisticsWithinPeriod(
                TransactionInterface::EXPENSE,
                $expenses
            )
        );

        $account->setTopIncomeCategories(
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
