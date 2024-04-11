<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\ExecutableInterface;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Entity\ValuableInterface;
use App\Pagination\Paginator;
use App\Repository\TransactionRepository;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Cache\InvalidArgumentException;

final class AssetsManager
{
    private EntityManagerInterface $em;

    private FixerService $fixerService;

    private TransactionRepository $transactionRepo;

    public function __construct(EntityManagerInterface $em, FixerService $fixerService)
    {
        $this->em = $em;
        $this->fixerService = $fixerService;
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
    }

    #[ArrayShape(['list' => 'mixed', 'totalValue' => 'float', 'count' => 'mixed'])]
    public function generateTransactionPaginationData(
        CarbonInterface $after,
        CarbonInterface $before,
        ?string $type = null,
        ?array $categories = null,
        ?array $accounts = null,
        ?array $excludedCategories = [],
        bool $withChildCategories = true,
        bool $onlyDrafts = false,
        int $perPage = Paginator::PER_PAGE,
        int $page = 1,
        string $orderField = TransactionRepository::ORDER_FIELD,
        string $order = TransactionRepository::ORDER
    ): array {
        $paginator = $this
            ->transactionRepo
            ->getPaginator(
                $after,
                $before,
                false,
                $type,
                ($withChildCategories && !empty($categories))
                    ? $this->em->getRepository(Category::class)->getCategoriesWithDescendantsByType($categories, $type)
                    : $categories,
                $accounts,
                $excludedCategories,
                $onlyDrafts,
                $perPage,
                ($page - 1) * $perPage,
                $orderField,
                $order
            );

        $list = $paginator->getResults();

        return [
            'list' => $list,
            'totalValue' => round(
                $this->sumMixedTransactions(
                    $paginator
                        ->getQuery()
                        ->setFirstResult(0)
                        ->setMaxResults(null)
                        ->getResult()
                ),
                2
            ),
            'count' => $paginator->getNumResults(),
        ];
    }

    public function calculateAverageWithinPeriod(array $transactions, CarbonPeriod $period): float
    {
        $byDate = [];

        $dates = $period->toArray();
        foreach ($dates as $after) {
            $before = next($dates);

            if ($before !== false) {
                $transactionsByDate = array_filter(
                    $transactions,
                    static function (TransactionInterface $transaction) use ($after, $before) {
                        return $transaction->getExecutedAt()->startOfDay()->between($after, $before);
                    }
                );
                $byDate[$after->toDateString()] = count($transactionsByDate)
                    ? $this->sumTransactions($transactionsByDate) / count($transactionsByDate)
                    : 0;
            }
        }

        return array_sum(array_values($byDate)) / count($dates);
    }

    public function calculateAverageTransaction(array $transactions = []): float
    {
        $sum = array_reduce($transactions, static function (float $acc, TransactionInterface $transaction) {
            return $acc + $transaction->getValue();
        }, 0);

        return $sum / (count($transactions) ?: 1);
    }

    public function sumTransactionsFiltered(
        ?string $type,
        CarbonInterface $after,
        CarbonInterface $before,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = []
    ): float {
        return $this->sumMixedTransactions(
            $this->transactionRepo->getList(
                $after,
                $before,
                $type,
                $categories,
                $accounts,
                $excludedCategories
            )
        );
    }

    public function sumMixedTransactions(array $transactions, ?string $currency = null): float
    {
        return array_reduce($transactions, static function ($carry, TransactionInterface $transaction) use ($currency) {
            return $carry + ($transaction->getConvertedValue($currency) * ($transaction->isExpense() ? -1 : 1));
        }, 0);
    }

    /**
     * Sum given transactions value in user's base currency
     */
    public function sumTransactions(array $transactions, ?string $currency = null): float
    {
        return array_reduce($transactions, static function ($carry, TransactionInterface $transaction) use ($currency) {
            return $carry + $transaction->getConvertedValue($currency);
        }, 0);
    }

    /**
     * Generates array of converted values to all base fiat currencies
     * @throws InvalidArgumentException
     */
    public function convert(ValuableInterface $entity): array
    {
        return $this->fixerService->convert(
            $entity->{'get'.ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    /**
     * Converts entity's value to a specified(or base if null given) currency
     * @throws InvalidArgumentException
     */
    public function convertTo(ValuableInterface $entity, ?string $toCurrency = null): float
    {
        return $this->fixerService->convertTo(
            $entity->{'get'.ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $toCurrency ?? $entity->getCurrency(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }
}
