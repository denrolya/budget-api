<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\StatisticsManager;

final class CategoriesTreeProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private StatisticsManager                   $statisticsManager,
        private CollectionDataProviderInterface $collectionDataProvider,
    )
    {
    }

    public function getCollection(string $resourceClass, string $operationName = null, array $context = []): iterable
    {
        $context['filters'] = $context['filters'] ?? [];
        $context['filters']['type'] = $context['filters']['type'] ?? TransactionInterface::EXPENSE;
        $context['filters']['exists']['root'] = false;
        $context['filters']['isAffectingProfit'] = true;
        $before = $context['filters']['transactions.executedAt']['before'];
        $after = $context['filters']['transactions.executedAt']['after'];
        unset($context['filters']['transactions.executedAt']);

        $categories = (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);

        $transactionsContext = [
            'filters' => [
                'isDraft' => false,
                'type' => $context['filters']['type'],
                'category.isAffectingProfit' => true,
                'executedAt' => [
                    'before' => $before,
                    'after' => $after,
                ],
            ],
            'groups' => 'transaction:collection:read',
            'operation_type' => 'collection',
            "collection_operation_name" => 'categories_timeline',
            "resource_class" => Transaction::class,
            "iri_only" => false,
            "input" => null,
            "output" => null,
        ];

        $transactions = (array)$this->collectionDataProvider->getCollection(
            Transaction::class,
            'categories_timeline',
            $transactionsContext
        );

        yield $this->statisticsManager->generateCategoryTreeWithValues($categories, $transactions);
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Category::class && $operationName === 'categories_tree';
    }
}
