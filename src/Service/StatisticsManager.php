<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Repository\ExpenseRepository;
use App\Repository\TransactionRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\ArrayShape;

final class StatisticsManager
{
    private TransactionRepository $transactionRepo;

    private ExpenseRepository $expenseRepo;

    public function __construct(
        private AssetsManager          $assetsManager,
        private EntityManagerInterface $em,
    )
    {
        $this->expenseRepo = $this->em->getRepository(Expense::class);
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
    }

    /**
     * Generates transactions statistics grouped by types. With given interval also grouped by date interval
     */
    public function generateMoneyFlowStatistics(
        CarbonInterface $from,
        CarbonInterface $to,
        ?CarbonInterval $interval = null,
        ?array          $types = null,
        ?array          $accounts = [],
        ?array          $categories = []): array
    {
        if(empty($types)) {
            $types = [TransactionInterface::EXPENSE, TransactionInterface::INCOME];
        }

        $result = [];

        if($interval) {
            $period = new CarbonPeriod($from, $interval, $to);
            foreach($period as $date) {
                $temporaryResult = [
                    'date' => $date->timestamp,
                ];

                foreach($types as $type) {
                    $temporaryResult[$type] = $this->assetsManager->sumTransactionsFiltered(
                        $type,
                        $date,
                        $date->copy()->add($period->interval)->subDay(),
                        $categories,
                        $accounts
                    );
                }

                $result[] = $temporaryResult;
            }
        } else {
            foreach($types as $type) {
                $result[$type] = $this->assetsManager->sumTransactionsFiltered(
                    $type,
                    $from,
                    $to,
                    $categories,
                    $accounts
                );
            }
        }

        return $result;
    }

    /**
     * Expenses & Incomes within given period with floating step
     */
    public function generateIncomeExpenseStatistics(CarbonInterface $from, CarbonInterface $to): array
    {
        $factor = ($to->timestamp - $from->timestamp) / .04;

        $result = [];

        $incomes = $this->transactionRepo->getList($from, $to, TransactionInterface::INCOME);
        $expenses = $this->transactionRepo->getList($from, $to, TransactionInterface::EXPENSE);

        while($from->isBefore($to)) {
            $iterator = $from->copy()->add($factor . " milliseconds");
            $expense = $this->assetsManager->sumTransactions(
                array_filter($expenses, static function (Expense $expense) use ($from, $iterator) {
                    return $expense->getExecutedAt()->isBetween($from, $iterator);
                })
            );
            $income = $this->assetsManager->sumTransactions(
                array_filter($incomes, static function (Income $income) use ($from, $iterator) {
                    return $income->getExecutedAt()->isBetween($from, $iterator);
                })
            );

            $from = $iterator;
            $result[] = [
                'date' => $from->timestamp,
                'expense' => -$expense,
                'income' => $income,
            ];
        }

        return $result;
    }

    public function generateCategoryTreeStatisticsWithinPeriod(string $type, array $transactions): array
    {
        // fetch all transactions once & pass them
        $repo = $this->em->getRepository(
            $type === TransactionInterface::INCOME
                ? IncomeCategory::class
                : ExpenseCategory::class
        );

        $tree = $repo->generateCategoryTree();

        foreach($tree as $rootCategory) {
            $categoryTransactions = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($rootCategory) {
                    return $transaction->getRootCategory()->getId() === $rootCategory['id'];
                }
            );

            foreach($categoryTransactions as $transaction) {
                $this->updateValueInCategoryTree(
                    $tree,
                    $transaction->getCategory()->getName(),
                    $transaction->getValue());
            }
        }

        $this->calculateTotalCategoryValueInCategoryTree($tree);

        return $tree;
    }

    #[ArrayShape(['min' => 'array', 'max' => 'array'])]
    public function generateMinMaxByMonthExpenseStatistics(CarbonInterface $from, CarbonInterface $to, string $category): array
    {
        $result = [
            'min' => [
                'value' => 0,
                'when' => null,
            ],
            'max' => [
                'value' => 0,
                'when' => null,
            ],
        ];

        $now = Carbon::now();
        $endOfMonth = $now->copy()->endOfMonth();

        if($now->isBefore($to)) {
            if($now->isSameDay($endOfMonth)) {
                $to = $endOfMonth;
            } else {
                $to = $now->previous('month');
            }
        }

        $period = new CarbonPeriod(
            $from->startOfMonth(),
            '1 month',
            $to->endOfMonth()
        );

        foreach($period as $key => $date) {
            $sum = $this->assetsManager->sumTransactionsFiltered(
                TransactionInterface::EXPENSE,
                $date->startOfMonth(),
                $date->copy()->endOfMonth(),
                [$category]
            );

            if($key === 0) {
                $result['min']['value'] = $sum;
                $result['min']['when'] = $date->toDateString();
                $result['max']['value'] = $sum;
                $result['max']['when'] = $date->toDateString();
            }

            if($sum < $result['min']['value']) {
                $result['min']['value'] = $sum;
                $result['min']['when'] = $date->toDateString();
            }

            if($sum > $result['max']['value']) {
                $result['max']['value'] = $sum;
                $result['max']['when'] = $date->toDateString();
            }
        }

        return $result;
    }

    /**
     * Generates account value distribution statistics within given period
     */
    public function generateAccountExpenseDistributionStatistics(CarbonInterface $from, CarbonInterface $to): array
    {
        $result = [];
        $accounts = $this->em->getRepository(Account::class)->findAll();

        /** @var Account $account */
        foreach($accounts as $account) {
            $expenses = $this->transactionRepo->getList($from, $to, TransactionInterface::EXPENSE, null, [$account]);
            $amount = $this->assetsManager->sumTransactions($expenses, $account->getCurrency());
            $value = $this->assetsManager->sumTransactions($expenses);

            if(!$value) {
                continue;
            }

            $result[] = [
                'account' => $account,
                'amount' => $amount,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * Sums categories' transactions and groups them by timeframe within given period
     */
    public function generateCategoriesOnTimelineStatistics(CarbonPeriod $period, ?array $categories): ?array
    {
        if(empty($categories)) {
            return null;
        }

        $result = [];
        $start = $period->getStartDate();
        $end = $period->getEndDate();

        foreach($categories as $categoryId) {
            if(!$category = $this->em->getRepository(Category::class)->find($categoryId)) {
                continue;
            }

            $name = $category->getName();

            $result[$name] = $this->sumTransactionsByDateInterval(
                $period,
                $this->transactionRepo->getList(
                    $start,
                    $end,
                    null,
                    [$name]
                )
            );
        }

        return $result;
    }

    /**
     * Calculate total amount of assets
     */
    public function calculateTotalAssets(): float
    {
        $accounts = $this->em->getRepository(Account::class)->findAll();
        $sum = 0;

        foreach($accounts as $account) {
            $sum += $account->getValue();
        }

        return $sum;
    }

    /**
     * Calculates the sum of expenses made today converted to base currency
     */
    public function calculateTodayExpenses(): float
    {
        $todayExpenses = $this
            ->expenseRepo
            ->getOnGivenDay(CarbonImmutable::today());

        return $this->assetsManager->sumTransactions($todayExpenses);
    }

    /**
     * Calculate the sum of total month incomes
     */
    public function calculateMonthIncomes(CarbonInterface $month): float
    {
        return $this->assetsManager->sumTransactionsFiltered(
            TransactionInterface::INCOME,
            $month->startOfMonth(),
            $month->endOfMonth()
        );
    }

    /**
     * Calculate amount of money spent on rent and utilities within given period
     */
    public function calculateRentExpensesWithinPeriod(CarbonInterface $from, CarbonInterface $to): float
    {
        $rentCategories = $this->assetsManager->getTypedCategoriesWithChildren(TransactionInterface::EXPENSE, [ExpenseCategory::CATEGORY_RENT, ExpenseCategory::CATEGORY_UTILITIES]);

        return $this->assetsManager->sumTransactions(
            $this->expenseRepo->findWithinPeriod($from, $to, true, $rentCategories)
        );
    }

    /**
     * Calculate amount of money spent on food within given period
     */
    public function calculateFoodExpensesWithinPeriod(CarbonInterface $from, CarbonInterface $to, bool $generateForPreviousPeriod = false): float|array
    {
        $foodCategories = $this->assetsManager->getTypedCategoriesWithChildren(TransactionInterface::EXPENSE, [ExpenseCategory::CATEGORY_FOOD]);

        $sumForCurrentPeriod = $this->assetsManager->sumTransactionsFiltered(
            TransactionInterface::EXPENSE,
            $from,
            $to,
            $foodCategories
        );

        if($generateForPreviousPeriod) {
            $previousPeriod = $this->generatePreviousTimeperiod($from, $to);

            $sumForPreviousPeriod = $this->assetsManager->sumTransactionsFiltered(
                TransactionInterface::EXPENSE,
                $previousPeriod->getStartDate(),
                $previousPeriod->getEndDate(),
                $foodCategories
            );

            return [
                'current' => $sumForCurrentPeriod,
                'previous' => $sumForPreviousPeriod,
            ];
        }

        return $sumForCurrentPeriod;
    }

    /**
     * Calculate average daily expense for given period converted to base currency
     */
    public function calculateAverageDailyExpenseWithinPeriod(CarbonInterface $from, CarbonInterface $to): float
    {
        $expenseSum = $this->assetsManager->sumTransactionsFiltered(
            TransactionInterface::EXPENSE,
            $from,
            $to,
            [ExpenseCategory::CATEGORY_RENT]
        );

        return $expenseSum / $to->endOfDay()->diffInDays($from->startOfDay());
    }

    public function sumTransactionsByDateInterval(CarbonPeriod $period, array $transactions = []): array
    {
        $result = [];
        foreach($period as $date) {
            $filteredTransactions = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($date, $period) {
                    return $transaction->getExecutedAt()->startOfDay()->between(
                        $date,
                        $date->copy()->add($period->interval)->subDay()->endOf('day')
                    );
                });

            $result[] = [
                'date' => $date->timestamp,
                'value' => $this->assetsManager->sumTransactions($filteredTransactions),
            ];
        }

        return $result;
    }

    /**
     * Generated previous period daterange for given dates(previous month, week, day, quarter...)
     */
    public function generatePreviousTimeperiod(CarbonInterface $from, CarbonInterface $to): CarbonPeriod
    {
        return ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($to->endOfMonth()))
            ? new CarbonPeriod(
                $from->sub('month', ($to->year - $from->year + 1) * abs($to->month - $from->month + 1)),
                $from->sub('days', 1)
            )
            : new CarbonPeriod(
                $from->sub('days', $to->diffInDays($from) + 1),
                $from->sub('days', 1)
            );
    }

    /**
     * Given category tree generated with ExpenseCategoryRepository::generateCategoryTree
     * find the needle category and update its value
     */
    private function updateValueInCategoryTree(array &$haystack, string $needle, float $value): void
    {
        foreach($haystack as &$child) {
            if($child['name'] === $needle) {
                $child['value'] = isset($child['value']) ? $child['value'] + $value : $value;
            } elseif(!empty($child['children'])) {
                $this->updateValueInCategoryTree($child['children'], $needle, $value);
            }
        }
    }

    /**
     * Given category tree generated with ExpenseCategoryRepository::generateCategoryTree
     * calculate the total value of all categories & their children
     */
    private function calculateTotalCategoryValueInCategoryTree(array &$haystack): void
    {
        foreach($haystack as &$child) {
            if(empty($child['children'])) {
                $child['total'] = $child['value'] ?? 0;
            } else {
                $this->calculateTotalCategoryValueInCategoryTree($child['children']);

                $childrenTotal = array_reduce($child['children'], static function (float $carry, array $el) {
                    return isset($el['total']) ? $carry + $el['total'] : $carry;
                }, 0);
                $child['total'] = isset($child['value']) ? $child['value'] + $childrenTotal : $childrenTotal;
            }
        }
    }
}
