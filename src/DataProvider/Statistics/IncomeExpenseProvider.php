<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;

final class IncomeExpenseProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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

        $from = isset($context['filters']['executedAt']['after'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['after'])->startOfDay()
            : CarbonImmutable::now()->startOfYear()->startOfDay();
        $to = isset($context['filters']['executedAt']['before'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['before'])->endOfDay()
            : CarbonImmutable::now()->endOfYear()->endOfDay();

        yield $this->statisticsManager->generateIncomeExpenseStatistics(
            $from,
            $to,
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context),
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'income_expense';
    }
}
