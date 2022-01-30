<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;

/**
 * TODO: Implement
 */
final class TransactionStatisticsCategoriesTimelineProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private AssetsManager                   $assetsManager,
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
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['executedAt']['after'])
            : CarbonImmutable::now()->startOfYear();
        $to = isset($context['filters']['executedAt']['before'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['executedAt']['before'])
            : CarbonImmutable::now()->endOfYear();
        $interval = isset($context['filters']['interval'])
            ? CarbonInterval::createFromDateString($context['filters']['interval'])
            : CarbonInterval::createFromDateString('1 month');

        $transactions = $this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);

        yield [
            "Food & Drinks" => [
                [
                    "date" => 1609459200,
                    "value" => 11523.06,
                ],
                [
                    "date" => 1612137600,
                    "value" => 11746.29,
                ],
                [
                    "date" => 1614556800,
                    "value" => 15592.47,
                ],
                [
                    "date" => 1617235200,
                    "value" => 16088.2,
                ],
                [
                    "date" => 1619827200,
                    "value" => 13072.07,
                ],
                [
                    "date" => 1622505600,
                    "value" => 13515.29,
                ],
                [
                    "date" => 1625097600,
                    "value" => 11461.48,
                ],
                [
                    "date" => 1627776000,
                    "value" => 10426.23,
                ],
                [
                    "date" => 1630454400,
                    "value" => 15540.630114176,
                ],
                [
                    "date" => 1633046400,
                    "value" => 13323.62,
                ],
                [
                    "date" => 1635724800,
                    "value" => 11239.09,
                ],
                [
                    "date" => 1638316800,
                    "value" => 18389.06,
                ],
            ],
            "Rent" => [
                [
                    "date" => 1609459200,
                    "value" => 6000,
                ],
                [
                    "date" => 1612137600,
                    "value" => 6000,
                ],
                [
                    "date" => 1614556800,
                    "value" => 6000,
                ],
                [
                    "date" => 1617235200,
                    "value" => 6000,
                ],
                [
                    "date" => 1619827200,
                    "value" => 6000,
                ],
                [
                    "date" => 1622505600,
                    "value" => 6000,
                ],
                [
                    "date" => 1625097600,
                    "value" => 6000,
                ],
                [
                    "date" => 1627776000,
                    "value" => 6000,
                ],
                [
                    "date" => 1630454400,
                    "value" => 6000,
                ],
                [
                    "date" => 1633046400,
                    "value" => 6000,
                ],
                [
                    "date" => 1635724800,
                    "value" => 2302.92,
                ],
                [
                    "date" => 1638316800,
                    "value" => 6000,
                ],
            ],
            "Gas" => [
                [
                    "date" => 1609459200,
                    "value" => 1698.4,
                ],
                [
                    "date" => 1612137600,
                    "value" => 2028.48,
                ],
                [
                    "date" => 1614556800,
                    "value" => 1552,
                ],
                [
                    "date" => 1617235200,
                    "value" => 1329,
                ],
                [
                    "date" => 1619827200,
                    "value" => 985,
                ],
                [
                    "date" => 1622505600,
                    "value" => 559.2,
                ],
                [
                    "date" => 1625097600,
                    "value" => 433.38,
                ],
                [
                    "date" => 1627776000,
                    "value" => 218.7,
                ],
                [
                    "date" => 1630454400,
                    "value" => 175.78,
                ],
                [
                    "date" => 1633046400,
                    "value" => 151.81,
                ],
                [
                    "date" => 1635724800,
                    "value" => 804.12,
                ],
                [
                    "date" => 1638316800,
                    "value" => 4562.2,
                ],
            ],
        ];
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'categoriesTimeline';
    }
}
