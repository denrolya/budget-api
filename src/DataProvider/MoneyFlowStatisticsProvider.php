<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\DenormalizedIdentifiersAwareItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\TimespanStatistics;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;

final class MoneyFlowStatisticsProvider implements DenormalizedIdentifiersAwareItemDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager $assetsManager,
    )
    {
    }

    // TODO: How to properly use custom resource filters
    // TODO: Get request parameters from, to, timeframe
    // TODO: Nested array_key_exists???
    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): TimespanStatistics
    {
        $from = CarbonImmutable::now()->startOfYear();
        $to = CarbonImmutable::now()->endOfYear();
        $interval = CarbonInterval::createFromDateString('1 month');
        if(array_key_exists('filters', $context)) {
            $from = array_key_exists('from', $context['filters']) ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['from']) : $from;
            $to = array_key_exists('to', $context['filters']) ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['to']) : $to;
            $interval = array_key_exists('interval', $context['filters']) ? CarbonInterval::createFromDateString($context['filters']['period']) : $interval;
        }

        return new TimespanStatistics(
            $operationName,
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
