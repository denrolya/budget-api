<?php

namespace App\DataProvider\Statistics;

use ApiPlatform\Core\DataProvider\CollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\ContextAwareCollectionDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\ExpenseCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use Doctrine\ORM\EntityManagerInterface;

final class ByWeekdaysProvider implements ContextAwareCollectionDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private CollectionDataProviderInterface $collectionDataProvider,
        private EntityManagerInterface $em,
    )
    {
    }

    public function getCollection(string $resourceClass, string $operationName = null, array $context = []): iterable
    {
        $context['filters'] = $context['filters'] ?? [];
        $context['filters']['category.isAffectingProfit'] = true;
        $context['filters']['isDraft'] = false;

        $transactions = $this->collectionDataProvider->getCollection($resourceClass, $operationName, $context);


        yield $this->test((array)$transactions);
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        dump($resourceClass === Transaction::class && $operationName === 'by_weekdays', $operationName);

        return $resourceClass === Transaction::class && $operationName === 'by_weekdays';
    }

    private function test(array $transactions): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $result = array_map(static function ($day) {
            return [
                'name' => $day,
                'values' => [],
            ];
        }, $days);

        $rootCategories = $this->em->getRepository(ExpenseCategory::class)->findBy(['root' => null, 'isTechnical' => false]);

        foreach($rootCategories as $category) {
            $categoryTransactions = array_filter($transactions, static function (TransactionInterface $transaction) use ($category) {
                return $transaction->getRootCategory()->getId() === $category->getId();
            });

            foreach($categoryTransactions as $transaction) {
                if(!array_key_exists($category->getName(), $result[$transaction->getExecutedAt()->dayOfWeekIso - 1]['values'])) {
                    $result[$transaction->getExecutedAt()->dayOfWeekIso - 1]['values'][$category->getName()] = 0;
                }

                $result[$transaction->getExecutedAt()->dayOfWeekIso - 1]['values'][$category->getName()] += $transaction->getValue();
            }
        }

        return $result;
    }

}
