<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;

final class TransactionStatisticsCategoriesTreeProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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
        $context['filters']['type'] = $context['filters']['type'] ?? TransactionInterface::EXPENSE;
        $context['filters']['isDraft'] = false;

        yield $this->assetsManager->generateCategoryTreeStatisticsWithinPeriod(
            $context['filters']['type'] ?? TransactionInterface::EXPENSE,
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context)
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'categoriesTree';
    }
}
