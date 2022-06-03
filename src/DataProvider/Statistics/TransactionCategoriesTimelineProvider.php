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

final class TransactionCategoriesTimelineProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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
            ? CarbonImmutable::parse($context['filters']['executedAt']['after'])
            : CarbonImmutable::now()->startOfYear();
        $to = isset($context['filters']['executedAt']['before'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['before'])
            : CarbonImmutable::now()->endOfYear();
        $interval = isset($context['filters']['interval'])
            ? CarbonInterval::createFromDateString($context['filters']['interval'])
            : CarbonInterval::createFromDateString('1 month');

        $categories = $context['filters']['categories'] ?? [];

        $transactions = $this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);

        yield $this->statisticsManager->generateCategoriesOnTimelineStatistics(
            new CarbonPeriod($from, $interval, $to),
            $categories,
            (array)$transactions,
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'categoriesTimeline';
    }
}
