<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;

/**
 * TODO: Try group by account/category and subgroup by date(it is grouped by date right now)
 */
final class TransactionMoneyFlowProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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

        yield $this->generateMoneyFlowStatistics(
            new CarbonPeriod($from, $interval, $to),
            (array)$this->collectionDataProvider->getCollection($resourceClass, $operationName, $context)
        );
    }

    /**
     * TODO: Move to StatisticsManager and replace existing methods
     */
    private function generateMoneyFlowStatistics(CarbonPeriod $period, array $transactions): array
    {
        $result = [];
        foreach($period as $date) {
            $result[] = [
                'date' => $date->timestamp,
                'expense' => $this->assetsManager->sumTransactions(
                    array_filter($transactions, static function (TransactionInterface $t) use ($date, $period) {
                        return $t->isExpense() && $t->getExecutedAt()->isBetween(
                                $date,
                                $date->copy()->add($period->interval)->subDay(),
                            );
                    })
                ),
                'income' => $this->assetsManager->sumTransactions(
                    array_filter($transactions, static function (TransactionInterface $t) use ($date, $period) {
                        return $t->isIncome() && $t->getExecutedAt()->isBetween(
                                $date,
                                $date->copy()->add($period->interval)->subDay(),
                            );
                    })
                ),
            ];
        }

        return $result;
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'money_flow';
    }
}
