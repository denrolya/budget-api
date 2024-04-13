<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
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
        private AssetsManager $assetsManager,
        private EntityManagerInterface $em,
    ) {
        $this->expenseRepo = $this->em->getRepository(Expense::class);
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
        $this->expenseCategoryRepo = $this->em->getRepository(ExpenseCategory::class);
    }

    /**
     * Generates transactions statistics grouped by types. With given interval also grouped by date interval
     */
    public function calculateTransactionsValueByPeriod(
        CarbonPeriod $period,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
    ): array {
        $result = [];
        $dates = $period->toArray();

        foreach ($dates as $index => $after) {
            $before = $index === count($dates) - 1 ? $period->getEndDate() : $dates[$index + 1]->copy()->subSecond();

            $transactions = $this->transactionRepo->getList(
                after: $after,
                before: $before,
                type: $type,
                categories: $categories,
                accounts: $accounts
            );

            $expenses = [];
            $incomes = [];

            foreach ($transactions as $transaction) {
                if ($transaction->getType() === TransactionInterface::EXPENSE) {
                    $expenses[] = $transaction;
                } elseif ($transaction->getType() === TransactionInterface::INCOME) {
                    $incomes[] = $transaction;
                }
            }

            $result[] = [
                'after' => $after->timestamp,
                'before' => $before->timestamp,
                'expense' => $this->assetsManager->sumTransactions($expenses),
                'income' => $this->assetsManager->sumTransactions($incomes),
            ];
        }

        return $result;
    }

    public function generateCategoryTreeWithValues(
        array $transactions,
        string $type = null,
        array $categories = []
    ): array {
        $categories = $categories ?: $this->getRootCategories($type);

        foreach ($categories as $category) {
            $categoryId = $category->getId();
            $categoryTransactions = array_filter(
                $transactions,
                static fn(TransactionInterface $t) => $t->getCategory()->getId() === $categoryId
            );
            $value = $this->assetsManager->sumTransactions($categoryTransactions);
            $category->setValue($value);

            $nestedTransactions = array_filter(
                $transactions,
                static fn(TransactionInterface $t) => $t->getCategory()->isChildOf($category)
            );
            $category->setTotal($this->assetsManager->sumTransactions($nestedTransactions));

            if (!empty($nestedTransactions) && $category->hasChildren()) {
                $this->generateCategoryTreeWithValues(
                    transactions: $nestedTransactions,
                    type: $type,
                    categories: $category->getChildren()->toArray()
                );
            }
        }

        return $categories;
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
        foreach ($dates as $key => $after) {
            $before = next($dates);

            if ($before === false) {
                $before = $period->getEndDate();
            }

            $sum = $this->assetsManager->sumTransactions(
                array_filter(
                    $transactions,
                    static function (TransactionInterface $transaction) use ($after, $before) {
                        $transactionDate = $transaction->getExecutedAt();

                        return $transactionDate->greaterThanOrEqualTo($after) && $transactionDate->lessThan($before);
                    }
                )
            );

            if ($key === 0) {
                $result['min']['value'] = $sum;
                $result['min']['when'] = $after->copy()->toDateString();
                $result['max']['value'] = $sum;
                $result['max']['when'] = $after->copy()->toDateString();
            }

            if ($sum < $result['min']['value']) {
                $result['min']['value'] = $sum;
                $result['min']['when'] = $after->copy()->toDateString();
            }

            if ($sum > $result['max']['value']) {
                $result['max']['value'] = $sum;
                $result['max']['when'] = $after->copy()->toDateString();
            }
        }

        return $result;
    }

    /**
     * OPTIMIZED BY CHATPGT
     * Generates account value distribution statistics within given transactions array
     */
    public function generateAccountDistributionStatistics(array $transactions): array
    {
        $result = [];
        $accountExpenses = [];

        // Group transactions by account
        foreach ($transactions as $transaction) {
            $accountId = $transaction->getAccount()->getId();
            if (!isset($accountExpenses[$accountId])) {
                $accountExpenses[$accountId] = [
                    'currency' => $transaction->getAccount()->getCurrency(),
                    'transactions' => [],
                ];
            }
            $accountExpenses[$accountId]['transactions'][] = $transaction;
        }

        foreach ($accountExpenses as $accountId => $accountData) {
            $amount = $this->assetsManager->sumTransactions($accountData['transactions'], $accountData['currency']);
            $value = $this->assetsManager->sumTransactions($accountData['transactions']);

            if (!$value) {
                continue;
            }

            $account = $this->em->getRepository(Account::class)->find($accountId);

            if ($account) {
                $result[] = [
                    'account' => $account,
                    'amount' => $amount,
                    'value' => $value,
                ];
            }
        }

        return $result;
    }

    /**
     * Sums categories' transactions and groups them by timeframe within given period
     */
    public function generateCategoriesOnTimelineStatistics(
        CarbonPeriod $period,
        ?array $categories,
        array $transactions
    ): ?array {
        if (empty($categories)) {
            return null;
        }

        $result = [];
        $start = $period->getStartDate();
        $end = $period->getEndDate();

        foreach ($categories as $categoryId) {
            if (!$category = $this->em->getRepository(Category::class)->find($categoryId)) {
                continue;
            }

            $name = $category->getName();

            $categoryTransactionsWithinPeriod = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($category, $start, $end) {
                    return $transaction->getCategory()->isChildOf($category) && $transaction->getExecutedAt(
                        )->isBetween($start, $end);
                }
            );

            $result[$name] = $this->sumTransactionsByDateInterval($categoryTransactionsWithinPeriod, $period);
        }

        return $result;
    }

    /**
     * Find transaction category that holds the biggest cumulative value
     * Used for: mainIncomeSource
     */
    public function generateTopValueCategoryStatistics(array $transactions): ?array
    {
        $rootCategories = $this->em
            ->getRepository(Category::class)
            ->findBy([
                'root' => null,
                'isAffectingProfit' => true,
                'isTechnical' => false,
            ]);
        $max = 0;
        $result = null;

        foreach ($rootCategories as $category) {
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

            if ($value > $max) {
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

        foreach ($categories as $categoryName) {
            /** @var ExpenseCategory $category */
            $category = $this->expenseCategoryRepo->findOneBy(['name' => $categoryName]);
            $data = [
                'name' => $categoryName,
                'icon' => $category->getIcon(),
                'color' => $category->getColor(),
                'values' => [],
            ];

            foreach ($dates as $after) {
                $before = next($dates);

                if ($before !== false) {
                    $data['values'][] = $this->assetsManager->sumTransactions(
                        array_filter(
                            $transactions,
                            static function (TransactionInterface $transaction) use ($after, $before, $category) {
                                $transactionDate = $transaction->getExecutedAt();

                                return $transactionDate->isBetween($after, $before) && $transaction->getCategory(
                                    )->isChildOf($category);
                            }
                        )
                    );
                }
            }

            $result[] = $data;
        }

        return $result;
    }

    public function generateTransactionsValueByCategoriesByWeekdays(array $transactionsOrdered): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        $result = array_map(static fn($day) => ['name' => $day, 'values' => []], $days);

        $rootCategories = $this->em->getRepository(ExpenseCategory::class)->findRootCategories(['name' => 'ASC']);

        foreach ($rootCategories as $category) {
            $categoryTransactions = array_filter(
                $transactionsOrdered,
                static fn(TransactionInterface $transaction) => $transaction->getCategory()->isChildOf($category)
            );

            foreach ($categoryTransactions as $transaction) {
                $weekday = $transaction->getExecutedAt()->dayOfWeekIso - 1;
                $categoryName = $category->getName();

                $result[$weekday]['values'][$categoryName] = ($result[$weekday]['values'][$categoryName] ?? 0) + $transaction->getValue(
                    );
            }
        }

        foreach ($result as $index => &$weekdayData) {
            $weekdaysCount = $this->countWeekdays(
                $transactionsOrdered[0]->getExecutedAt(),
                end($transactionsOrdered)->getExecutedAt(),
                $index
            );

            $weekdayData['values'] = array_map(static fn($value) => $value / $weekdaysCount, $weekdayData['values']);
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
     * Calculate average daily expense for given period converted to base currency
     */
    public function calculateAverageDailyExpenseWithinPeriod(CarbonInterface $after, CarbonInterface $before): float
    {
        $expenseSum = $this->assetsManager->sumTransactionsFiltered(
            TransactionInterface::EXPENSE,
            $after,
            $before,
            [ExpenseCategory::CATEGORY_RENT]
        );

        return $expenseSum / $before->endOfDay()->diffInDays($after->startOfDay());
    }

    public function sumTransactionsByDateInterval(array $transactions, CarbonPeriod $period): array
    {
        $result = [];
        $dates = $period->toArray();
        foreach ($dates as $index => $after) {
            $before = $index === count($dates) - 1 ? $period->getEndDate() : $dates[$index + 1];

            if ($after->equalTo($before)) {
                continue;
            }

            $transactionsWithinPeriod = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($after, $before) {
                    $transactionDate = $transaction->getExecutedAt();

                    return $transactionDate->greaterThanOrEqualTo($after) && $transactionDate->lessThan($before);
                }
            );

            $result[] = [
                'date' => $after->timestamp,
                'value' => $this->assetsManager->sumTransactions($transactionsWithinPeriod),
            ];
        }

        return $result;
    }

    public function averageByPeriod(array $transactions, CarbonPeriod $period): array
    {
        $result = [];
        $dates = $period->toArray();
        foreach ($dates as $index => $after) {
            $before = $index === count($dates) - 1 ? $period->getEndDate() : $dates[$index + 1];

            if ($after->equalTo($before)) {
                continue;
            }

            $transactionsWithinPeriod = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($after, $before) {
                    $transactionDate = $transaction->getExecutedAt();

                    return $transactionDate->greaterThanOrEqualTo($after) && $transactionDate->lessThan($before);
                }
            );

            $result[] = [
                'date' => $after->timestamp,
                'value' => count($transactionsWithinPeriod) !== 0 ? $this->assetsManager->sumTransactions(
                        $transactionsWithinPeriod
                    ) / count($transactionsWithinPeriod) : 0,
            ];
        }

        return $result;
    }

    /**
     * Generated previous period daterange for given dates(previous month, week, day, quarter...)
     */
    public function generatePreviousTimeperiod(CarbonInterface $after, CarbonInterface $before): CarbonPeriod
    {
        return ($after->isSameDay($after->startOfMonth()) && $before->isSameDay($before->endOfMonth()))
            ? new CarbonPeriod(
                $after->sub('month', ($before->year - $after->year + 1) * abs($before->month - $after->month + 1)),
                $after->sub('days', 1)
            )
            : new CarbonPeriod(
                $after->sub('days', $before->diffInDays($after) + 1),
                $after->sub('days', 1)
            );
    }

    /**
     * Given category tree generated with ExpenseCategoryRepository::generateCategoryTree
     * find the needle category and update its value
     */
    private function updateValueInCategoryTree(array &$haystack, string $needle, float $value): void
    {
        foreach ($haystack as &$child) {
            if ($child['name'] === $needle) {
                $child['value'] = isset($child['value']) ? $child['value'] + $value : $value;
            } elseif (!empty($child['children'])) {
                $this->updateValueInCategoryTree($child['children'], $needle, $value);
            }
        }
    }

    private function getRootCategories(?string $type): array
    {
        $categoryClass = Category::class;
        if ($type === Category::EXPENSE_CATEGORY_TYPE) {
            $categoryClass = ExpenseCategory::class;
        } elseif ($type === Category::INCOME_CATEGORY_TYPE) {
            $categoryClass = IncomeCategory::class;
        }

        return $this->em->getRepository($categoryClass)->findRootCategories();
    }

    private function countWeekdays(CarbonInterface $after, CarbonInterface $before, int $weekday): int
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new \InvalidArgumentException(
                'Invalid weekday. Please provide a value between 0 and 6 (Sunday to Saturday).'
            );
        }

        $count = 0;
        $startDate = $after->copy()->startOfDay();
        $endDate = $before->copy()->startOfDay();

        while ($startDate->lte($endDate)) {
            if ($startDate->dayOfWeek === $weekday) {
                $count++;
            }
            $startDate = $startDate->addDay();
        }

        return $count;
    }
}
