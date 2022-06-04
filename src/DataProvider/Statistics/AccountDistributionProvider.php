<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\StatisticsManager;

final class AccountDistributionProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private StatisticsManager               $statisticsManager,
        private CollectionDataProviderInterface $collectionDataProvider,
    )
    {
    }

    public function getCollection(string $resourceClass, string $operationName = null, array $context = []): iterable
    {
        $context['filters'] = $context['filters'] ?? [];
        $context['filters']['category.isAffectingProfit'] = true;
        $context['filters']['isDraft'] = false;

        yield $this->statisticsManager->generateAccountDistributionStatistics(
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context),
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'account_distribution';
    }
}
