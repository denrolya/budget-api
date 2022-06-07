<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\AssetsManager;

final class SumProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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

        // TODO: If no interval - provide sum
        // TODO: If interval provided - sum by interval; return array of values with dates

        $transactions = (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);

        $result = isset($context['filters']['type'])
            ? $this->assetsManager->sumTransactions($transactions)
            : $this->assetsManager->sumMixedTransactions($transactions);

        yield $result;
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'sum';
    }
}
