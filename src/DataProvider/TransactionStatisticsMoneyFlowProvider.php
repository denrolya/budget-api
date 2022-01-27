<?php

namespace App\DataProvider;

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
final class TransactionStatisticsMoneyFlowProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
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
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['executedAt']['after'])
            : CarbonImmutable::now()->startOfYear();
        $to = isset($context['filters']['executedAt']['before'])
            ? CarbonImmutable::createFromFormat('d-m-Y', $context['filters']['executedAt']['before'])
            : CarbonImmutable::now()->endOfYear();
        $interval = isset($context['filters']['interval'])
            ? CarbonInterval::createFromDateString($context['filters']['interval'])
            : CarbonInterval::createFromDateString('1 month');

        $transactions = $this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);

        yield $this->generateMoneyFlowStatistics(
            new CarbonPeriod($from, $interval, $to),
            (array)$transactions
        );
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === Transaction::class && $operationName === 'moneyFlow';
    }

    /**
     * TODO: Move to AssetsManager and replace existing methods
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
}
