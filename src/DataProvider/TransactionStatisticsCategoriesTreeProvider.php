<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;

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
        $from = isset($context['filters']['from'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['from'])
            : CarbonImmutable::now()->startOfYear();
        $to = isset($context['filters']['to'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['to'])
            : CarbonImmutable::now()->endOfYear();

        yield $this->assetsManager->generateCategoryTreeStatisticsWithinPeriod($context['filters']['type'] ?? TransactionInterface::EXPENSE, $from, $to);
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'categoriesTree';
    }
}
