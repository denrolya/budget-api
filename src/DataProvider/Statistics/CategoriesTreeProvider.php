<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\StatisticsManager;

final class CategoriesTreeProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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
        $context['filters']['type'] = $context['filters']['type'] ?? TransactionInterface::EXPENSE;
        $context['filters']['isDraft'] = false;

        yield $this->statisticsManager->generateCategoryTreeStatisticsWithinPeriod(
            $context['filters']['type'] ?? TransactionInterface::EXPENSE,
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context)
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'categories_tree';
    }
}
