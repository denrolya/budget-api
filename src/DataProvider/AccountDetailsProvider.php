<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\DenormalizedIdentifiersAwareItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\ItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Account;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;

/**
 * TODO: Perhaps this thing can use query parameters to specify from/to date to get categories statistics
 */
class AccountDetailsProvider implements DenormalizedIdentifiersAwareItemDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(private AssetsManager $assetsManager, private ItemDataProviderInterface $itemDataProvider)
    {
    }

    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): ?object
    {
        $account = $this->itemDataProvider->getItem($resourceClass, $id, $operationName, $context);

        if (!$account) {
            return null;
        }
        $startOfDecade = (new CarbonImmutable())->startOfDecade();
        $endOfDecade = (new CarbonImmutable())->endOfDecade();

        $account->setTopExpenseCategories(
            $this->assetsManager->generateCategoryTreeStatisticsWithinPeriod(
                TransactionInterface::EXPENSE,
                $startOfDecade,
                $endOfDecade,
                $account
            )
        );

        $account->setTopIncomeCategories(
            $this->assetsManager->generateCategoryTreeStatisticsWithinPeriod(
                TransactionInterface::INCOME,
                $startOfDecade,
                $endOfDecade,
                $account
            )
        );

        return $account;
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Account::class && (array_key_exists('groups', $context) && $context['groups'] === 'account:item:read');
    }
}
