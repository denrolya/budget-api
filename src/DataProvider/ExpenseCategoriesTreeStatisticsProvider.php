<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\TimespanStatistics;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;

final class ExpenseCategoriesTreeStatisticsProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager $assetsManager,
    )
    {
    }

    public function getCollection(string $resourceClass, string $operationName = null, array $context = []): iterable
    {
        $from = isset($context['filters']['from'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['from'])
            : CarbonImmutable::now()->startOfYear();
        $to = isset($context['filters']['to'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['to'])
            : CarbonImmutable::now()->endOfYear();

        yield new TimespanStatistics(
            $from,
            $to,
            null,
            $this->assetsManager->generateCategoryTreeStatisticsWithinPeriod(TransactionInterface::EXPENSE, $from, $to)
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === TimespanStatistics::class && $operationName === 'expenseCategoriesTree';
    }
}
