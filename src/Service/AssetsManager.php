<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\ExecutableInterface;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Entity\ValuableInterface;
use App\Pagination\Paginator;
use App\Repository\AccountRepository;
use App\Repository\ExpenseRepository;
use App\Repository\TransactionRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use JetBrains\PhpStorm\ArrayShape;

final class AssetsManager
{
    private EntityManagerInterface $em;

    private FixerService $fixerService;

    private TransactionRepository $transactionRepo;

    private ExpenseRepository $expenseRepo;

    private AccountRepository $accountRepo;

    public function __construct(EntityManagerInterface $em, FixerService $fixerService)
    {
        $this->em = $em;
        $this->fixerService = $fixerService;
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
        $this->accountRepo = $this->em->getRepository(Account::class);
    }

    #[ArrayShape(['list' => "mixed", 'totalValue' => "float", 'count' => "mixed"])]
    public function generateTransactionPaginationData(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string         $type = null,
        array           $categories = [],
        array           $accounts = [],
        array           $excludedCategories = [],
        bool            $withChildCategories = true,
        bool            $onlyDrafts = false,
        int             $limit = Paginator::PAGE_SIZE,
        int             $offset = 0,
        string          $orderField = TransactionRepository::ORDER_FIELD,
        string          $order = TransactionRepository::ORDER
    ): array
    {
        $paginator = $this
            ->transactionRepo
            ->getPaginator(
                $from,
                $to,
                false,
                $type,
                ($withChildCategories && !empty($categories))
                    ? $this->getTypedCategoriesWithChildren($type, $categories)
                    : $categories,
                $accounts,
                $excludedCategories,
                $onlyDrafts,
                $limit,
                $offset,
                $orderField,
                $order
            );

        $list = $paginator->getResults();

        return [
            'list' => $list,
            'totalValue' => $this->sumMixedTransactions($paginator
                ->getQuery()
                ->setFirstResult(0)
                ->setMaxResults(null)
                ->getResult()),
            'count' => $paginator->getNumResults(),
        ];
    }

    public function generateTransactionList(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string         $type = null,
        ?array          $categories = [],
        ?array          $accounts = [],
        ?array          $excludedCategories = [],
        bool            $withChildCategories = true,
        bool            $onlyDrafts = false,
        string          $orderField = TransactionRepository::ORDER_FIELD,
        string          $order = TransactionRepository::ORDER
    ): array
    {
        return $this
            ->transactionRepo
            ->getPaginator(
                $from,
                $to,
                true,
                $type,
                ($withChildCategories && !empty($categories)) ? $this->getTypedCategoriesWithChildren($type, $categories) : $categories,
                $accounts,
                $excludedCategories,
                $onlyDrafts,
                10,
                0,
                $orderField,
                $order
            )
            ->getQuery()
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getResult();
    }

    /**
     * Shorthand for generateTransactionList
     */
    public function generateExpenseTransactionList(
        CarbonInterface $from,
        CarbonInterface $to,
        ?array          $categories = [],
        ?array          $accounts = [],
        ?array          $excludedCategories = [],
        bool            $withChildCategories = true,
        bool            $onlyDrafts = false,
        string          $orderField = TransactionRepository::ORDER_FIELD,
        string          $order = TransactionRepository::ORDER
    ): array
    {
        return $this->generateTransactionList(
            $from,
            $to,
            TransactionInterface::EXPENSE,
            $categories,
            $accounts,
            $excludedCategories,
            $withChildCategories,
            $onlyDrafts,
            $orderField,
            $order
        );
    }

    /**
     * Shorthand for generateTransactionList
     */
    public function generateIncomeTransactionList(
        CarbonInterface $from,
        CarbonInterface $to,
        ?array          $categories = [],
        ?array          $accounts = [],
        ?array          $excludedCategories = [],
        bool            $withChildCategories = true,
        bool            $onlyDrafts = false,
        string          $orderField = TransactionRepository::ORDER_FIELD,
        string          $order = TransactionRepository::ORDER
    ): array
    {
        return $this->generateTransactionList(
            $from,
            $to,
            TransactionInterface::INCOME,
            $categories,
            $accounts,
            $excludedCategories,
            $withChildCategories,
            $onlyDrafts,
            $orderField,
            $order
        );
    }

    public function calculateAverage(CarbonInterface $from, CarbonInterface $to, string $timeframe, string $type): float
    {
        $transactions = $this->generateTransactionList($from, $to, $type);
        $period = new CarbonPeriod($from, "1 $timeframe", $to);

        $byDate = [];

        foreach($period as $date) {
            $transactionsByDate = array_filter($transactions, static function (TransactionInterface $transaction) use ($date, $timeframe) {
                return $transaction->getExecutedAt()->startOfDay()->between(
                    $date,
                    $date->copy()->endOf($timeframe)
                );
            });
            $byDate[$date->toDateString()] = count($transactionsByDate)
                ? $this->sumTransactions($transactionsByDate) / count($transactionsByDate)
                : 0;
        }

        return array_sum(array_values($byDate)) / $period->count();
    }

    public function calculateAverageTransaction(array $transactions = []): float
    {
        $sum = array_reduce($transactions, static function (float $acc, TransactionInterface $transaction) {
            return $acc + $transaction->getValue();
        }, 0);

        return $sum / (count($transactions) ?: 1);
    }

    public function sumTransactionsFiltered(?string $type, CarbonInterface $from, CarbonInterface $to, ?array $categories = [], ?array $accounts = [], ?array $excludedCategories = []): float
    {
        return $this->sumMixedTransactions(
            $this->generateTransactionList(
                $from,
                $to,
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
     */
    public function convert(ValuableInterface $entity): array
    {
        return $this->fixerService->convert(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    /**
     * Converts entity's value to a specified(or base if null given) currency
     */
    public function convertTo(ValuableInterface $entity, ?string $to = null): float
    {
        return $this->fixerService->convertTo(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $to ?? $entity->getCurrency(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    public function getTypedCategoriesWithChildren(?string $type = null, ?array $categories = []): array
    {
        $types = $type !== null ? $type : [TransactionInterface::EXPENSE, TransactionInterface::INCOME];
        $result = [];

        foreach($types as $t) {
            $repo = $this->em
                ->getRepository($t === TransactionInterface::EXPENSE ? ExpenseCategory::class : IncomeCategory::class);

            if(empty($categories)) {
                $result = $repo->findBy(['root' => null, 'isTechnical' => false]);

                $result = array_map(static function (Category $category) {
                    return $category->getName();
                }, $result);
            } else {
                foreach($categories as $category) {
                    /** @var Category $category */
                    $category = $repo->findOneBy(['name' => $category]);

                    if($category) {
                        $result = [...$result, ...$category->getDescendantsFlat(true)->toArray()];
                    }
                }
            }
        }

        return $result;
    }
}
