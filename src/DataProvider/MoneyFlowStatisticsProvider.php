<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\TimespanStatistics;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;

final class MoneyFlowStatisticsProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager $assetsManager,
    )
    {
    }

    // TODO: How to properly use custom resource filters
    // TODO: Get request parameters from, to, timeframe
    public function getCollection(string $resourceClass, string $operationName = null, array $context = []): iterable
    {
        $from = isset($context['filters']['from'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['from'])
            : CarbonImmutable::now()->startOfYear();
        $to = isset($context['filters']['to'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['to'])
            : CarbonImmutable::now()->endOfYear();
        $interval = isset($context['filters']['interval'])
            ? CarbonInterval::createFromDateString($context['filters']['interval'])
            : CarbonInterval::createFromDateString('1 month');

        yield new TimespanStatistics(
            $from,
            $to,
            $interval,
            $this->assetsManager->generateMoneyFlowStatistics(
                $from,
                $to,
                $interval
            )
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === TimespanStatistics::class && $operationName === 'moneyFlow';
    }
}
