<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\AssetsManager;

final class GroceriesAvgProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager                   $assetsManager,
        private CollectionDataProviderInterface $collectionDataProvider,
    )
    {
    }

    public function getCollection(string $resourceClass, string $operationName = null, array $context = []): iterable
    {
        $context['filters'] = $context['filters'] ?? [];
        $context['filters']['category.isAffectingProfit'] = true;
        $context['filters']['isDraft'] = false;

        $days = [];
        $transactions = (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);

        foreach($transactions as $transaction) {
            $day = $transaction->getExecutedAt()->dayOfWeekIso;

            $days[$day] = array_key_exists($day, $days) ? $days[$day] + 1 : 1;
        }

        yield [
            'average' => $this->assetsManager->calculateAverageTransaction($transactions),
            'dayOfWeek' => array_search(max($days), $days, true),
        ];
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'groceries_average';
    }
}
