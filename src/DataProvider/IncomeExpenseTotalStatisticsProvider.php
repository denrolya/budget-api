<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\DenormalizedIdentifiersAwareItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\TimespanStatistics;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;

final class IncomeExpenseTotalStatisticsProvider implements DenormalizedIdentifiersAwareItemDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager $assetsManager,
    )
    {
    }

    // TODO: Multiple operations, same URL but different requirements
    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): TimespanStatistics
    {
        $from = CarbonImmutable::now()->startOfYear();
        $to = CarbonImmutable::now()->endOfYear();

        if(array_key_exists('filters', $context)) {
            $from = array_key_exists('from', $context['filters']) ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['from']) : $from;
            $to = array_key_exists('to', $context['filters']) ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['to']) : $to;
        }

        return new TimespanStatistics(
            $operationName,
            $from,
            $to,
            null,
            $this->assetsManager->generateIncomeExpenseStatistics($from, $to)
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === TimespanStatistics::class && $operationName === 'incomeExpense';
    }
}
