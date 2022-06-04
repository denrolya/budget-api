<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\StatisticsManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;

final class TransactionMinMaxProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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
        $context['filters']['category.isAffectingProfit'] = true;
        $context['filters']['isDraft'] = false;

        $from = isset($context['filters']['executedAt']['after'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['after'])->startOfDay()
            : CarbonImmutable::now()->startOfYear()->startOfDay();
        $to = isset($context['filters']['executedAt']['before'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['before'])->endOfDay()
            : CarbonImmutable::now()->endOfYear()->endOfDay();
        $interval = isset($context['filters']['interval'])
            ? CarbonInterval::createFromDateString($context['filters']['interval'])
            : CarbonInterval::createFromDateString('1 month');

        $now = Carbon::now();
        $endOfMonth = $now->copy()->endOfMonth();

        if($now->isBefore($to)) {
            if($now->isSameDay($endOfMonth)) {
                $to = $endOfMonth;
            } else {
                $to = $now->previous('month');
            }
        }

        yield $this->statisticsManager->generateMinMaxByIntervalExpenseStatistics(
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context),
            new CarbonPeriod($from, $interval, $to)
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'min_max';
    }
}
