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
use App\Repository\ExpenseCategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\ArrayShape;

final class StatisticsManager
{
    private TransactionRepository $transactionRepo;

    private ExpenseRepository $expenseRepo;

    private ExpenseCategoryRepository $expenseCategoryRepo;

    public function __construct(
        private AssetsManager          $assetsManager,
        private EntityManagerInterface $em,
    )
    {
        $this->expenseRepo = $this->em->getRepository(Expense::class);
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
        $this->expenseCategoryRepo = $this->em->getRepository(ExpenseCategory::class);
    }

    /**
     * Generates transactions statistics grouped by types. With given interval also grouped by date interval
     */
    public function generateMoneyFlowStatistics(array $transactions, CarbonPeriod $period): array
    {
        $result = [];
        $dates = $period->toArray();
        foreach($dates as $from) {
            $to = next($dates);

            if($to === false) {
                $to = $period->getEndDate();
            }

            $result[] = [
                'date' => $from->timestamp,
                'expense' => $this->assetsManager->sumTransactions(
                    array_filter(
                        $transactions,
                        static function (TransactionInterface $t) use ($from, $to) {
                            $transactionDate = $t->getExecutedAt();

                            return $t->isExpense() && $transactionDate->greaterThanOrEqualTo($from) && $transactionDate->lessThan($to);
                        }
                    )
                ),
                'income' => $this->assetsManager->sumTransactions(
                    array_filter(
                        $transactions,
                        static function (TransactionInterface $t) use ($from, $to) {
                            $transactionDate = $t->getExecutedAt();

                            return $t->isIncome() && $transactionDate->greaterThanOrEqualTo($from) && $transactionDate->lessThan($to);
                        }
                    )
                ),
            ];
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
    public function generateMinMaxByIntervalExpenseStatistics(array $transactions, CarbonPeriod $period): array
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

        $dates = $period->toArray();
        foreach($dates as $key => $from) {
            $to = next($dates);

            if($to === false) {
                $to = $period->getEndDate();
            }

            $sum = $this->assetsManager->sumTransactions(
                array_filter(
                    $transactions,
                    static function (TransactionInterface $transaction) use ($from, $to) {
                        $transactionDate = $transaction->getExecutedAt();

                        return $transactionDate->greaterThanOrEqualTo($from) && $transactionDate->lessThan($to);
                    }
                )
            );

            if($key === 0) {
                $result['min']['value'] = $sum;
                $result['min']['when'] = $from->copy()->toDateString();
                $result['max']['value'] = $sum;
                $result['max']['when'] = $from->copy()->toDateString();
            }

            if($sum < $result['min']['value']) {
                $result['min']['value'] = $sum;
                $result['min']['when'] = $from->copy()->toDateString();
            }

            if($sum > $result['max']['value']) {
                $result['max']['value'] = $sum;
                $result['max']['when'] = $from->copy()->toDateString();
            }
        }

        return $result;
    }

    /**
     * Generates account value distribution statistics within given transactions array
     */
    public function generateAccountDistributionStatistics(array $transactions): array
    {
        $result = [];
        $accounts = $this->em->getRepository(Account::class)->findAll();

        /** @var Account $account */
        foreach($accounts as $account) {
            $expenses = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($account) {
                    return $transaction->getAccount()->getId() === $account->getId();
                }
            );
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
    public function generateCategoriesOnTimelineStatistics(CarbonPeriod $period, ?array $categories, array $transactions): ?array
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

            $categoryTransactionsWithinPeriod = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($category, $start, $end) {
                    return $transaction->getCategory()->isChildOf($category) && $transaction->getExecutedAt()->isBetween($start, $end);
                }
            );

            $result[$name] = $this->sumTransactionsByDateInterval($period, $categoryTransactionsWithinPeriod);
        }

        return $result;
    }

    /**
     * Find transaction category that holds the biggest cumulative value
     */
    public function generateTopValueCategoryStatistics(array $transactions): ?array
    {
        $rootCategories = $this->em
            ->getRepository(Category::class)
            ->findBy([
                'root' => null,
                'isTechnical' => false,
            ]);
        $max = 0;
        $result = null;

        foreach($rootCategories as $category) {
            $value = abs(
                $this->assetsManager->sumTransactions(
                    array_filter(
                        $transactions,
                        static function (TransactionInterface $transaction) use ($category) {
                            return $transaction->getCategory()->isChildOf($category);
                        }
                    )
                )
            );

            if($value > $max) {
                $max = $value;
                $result = [
                    'icon' => $category->getIcon(),
                    'name' => $category->getName(),
                    'value' => $max,
                ];
            }
        }

        return $result;
    }

    public function generateUtilityCostsStatistics(array $transactions, CarbonPeriod $period): array
    {
        $result = [];
        $dates = $period->toArray();
        $categories = [
            ExpenseCategory::CATEGORY_UTILITIES,
            ExpenseCategory::CATEGORY_UTILITIES_GAS,
            ExpenseCategory::CATEGORY_UTILITIES_WATER,
            ExpenseCategory::CATEGORY_UTILITIES_ELECTRICITY,
        ];

        foreach($categories as $categoryName) {
            /** @var ExpenseCategory $category */
            $category = $this->expenseCategoryRepo->findOneBy(['name' => $categoryName]);
            $data = [
                'name' => $categoryName,
                'icon' => $category->getIcon(),
                'color' => $category->getColor(),
                'values' => [],
            ];

            foreach($dates as $from) {
                $to = next($dates);

                if($to !== false) {
                    $data['values'][] = $this->assetsManager->sumTransactions(
                        array_filter(
                            $transactions,
                            static function (TransactionInterface $transaction) use ($from, $to, $category) {
                                $transactionDate = $transaction->getExecutedAt();

                                return $transactionDate->isBetween($from, $to) && $transaction->getCategory()->isChildOf($category);
                            }
                        )
                    );
                }
            }

            $result[] = $data;
        }

        return $result;
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

    public function sumTransactionsByDateInterval(CarbonPeriod $period, array $transactions): array
    {
        $dates = $period->toArray();
        $result = [];
        foreach($dates as $date) {
            $to = next($dates);

            if($to === false) {
                $to = $period->getEndDate();
            }

            $transactionsWithinPeriod = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($date, $to) {
                    $transactionDate = $transaction->getExecutedAt();

                    return $transactionDate->greaterThanOrEqualTo($date) && $transactionDate->lessThan($to);
                });

            $result[] = [
                'date' => $date->timestamp,
                'value' => $this->assetsManager->sumTransactions($transactionsWithinPeriod),
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
