<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;

final class TransactionAvgProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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

        $from = isset($context['filters']['executedAt']['after'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['after'])->startOfDay()
            : CarbonImmutable::now()->startOfYear()->startOfDay();
        $to = isset($context['filters']['executedAt']['before'])
            ? CarbonImmutable::parse($context['filters']['executedAt']['before'])->endOfDay()
            : CarbonImmutable::now()->endOfYear()->endOfDay();
        $interval = isset($context['filters']['interval'])
            ? CarbonInterval::createFromDateString($context['filters']['interval'])
            : CarbonInterval::createFromDateString('1 month');


        yield $this->assetsManager->calculateAverageWithinPeriod(
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context),
            new CarbonPeriod($from, $interval, $to),
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'avg';
    }
}
