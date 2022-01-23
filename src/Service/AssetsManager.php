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
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use JetBrains\PhpStorm\ArrayShape;

class AssetsManager
{
    protected const DECIMAL_PRECISION = 2;

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
        $this->expenseRepo = $this->em->getRepository(Expense::class);
        $this->accountRepo = $this->em->getRepository(Account::class);
    }

    public function generateTransactionPaginationList(
        array           $types,
        CarbonInterface $from,
        CarbonInterface $to,
        array           $categories = [],
        array           $accounts = [],
        array           $excludedCategories = [],
        bool            $withChildCategories = true,
        bool            $onlyDrafts = false,
        int             $limit = Paginator::PAGE_SIZE,
        int             $offset = 0,
        string          $orderField = TransactionRepository::ORDER_FIELD,
        string          $order = TransactionRepository::ORDER
    ): array|bool
    {
        if(!in_array(TransactionInterface::EXPENSE, $types, true) && !in_array(TransactionInterface::INCOME, $types, true)) {
            return false;
        }

        $paginator = $this
            ->transactionRepo
            ->getPaginator(
                $from,
                $to,
                false,
                $types,
                ($withChildCategories && !empty($categories))
                    ? $this->getTypedCategoriesWithChildren($types, $categories)
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
            'totalValue' => $this->calculateTotalValueOfTransactions($paginator->getQuery()),
            'count' => $paginator->getNumResults(),
        ];
    }

    public function generateTransactionList(
        CarbonInterface $from,
        CarbonInterface $to,
        ?array          $types,
        ?array          $categories = [],
        ?array          $accounts = [],
        ?array          $excludedCategories = [],
        bool            $withChildCategories = true,
        bool            $onlyDrafts = false,
        string          $orderField = TransactionRepository::ORDER_FIELD,
        string          $order = TransactionRepository::ORDER
    ): bool|array
    {
        if(empty($types)) {
            $types = [TransactionInterface::EXPENSE, TransactionInterface::INCOME];
        }

        if(!in_array(TransactionInterface::EXPENSE, $types, true) && !in_array(TransactionInterface::INCOME, $types, true)) {
            return false;
        }

        return $this
            ->transactionRepo
            ->getPaginator(
                $from,
                $to,
                true,
                $types,
                ($withChildCategories && !empty($categories)) ? $this->getTypedCategoriesWithChildren($types, $categories) : $categories,
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
    ): bool|array
    {
        return $this->generateTransactionList(
            $from,
            $to,
            [TransactionInterface::EXPENSE],
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
    ): bool|array
    {
        return $this->generateTransactionList(
            $from,
            $to,
            [TransactionInterface::INCOME],
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
                    $transactions = $this->generateTransactionList(
                        $date,
                        $date->copy()->add($period->interval)->subDay(),
                        [$type],
                        $categories,
                        $accounts
                    );
                    $temporaryResult[$type] = $this->sumTransactions($transactions);
                }

                $result[] = $temporaryResult;
            }
        } else {
            foreach($types as $type) {
                $transactions = $this->generateTransactionList(
                    $from,
                    $to,
                    [$type],
                    $categories,
                    $accounts
                );
                $result[$type] = $this->sumTransactions($transactions);
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

        $incomes = $this->generateIncomeTransactionList($from, $to);
        $expenses = $this->generateExpenseTransactionList($from, $to);

        while($from->isBefore($to)) {
            $iterator = $from->copy()->add($factor . " milliseconds");
            $expense = $this->sumTransactions(
                array_filter($expenses, static function (Expense $expense) use ($from, $iterator) {
                    return $expense->getExecutedAt()->isBetween($from, $iterator);
                })
            );
            $income = $this->sumTransactions(
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

    /**
     * TODO: This generates a shit ton of requests to database; to be optimized
     * Using the structure provided by ExpenseCategoryRepository::generateCategoryTree
     * calculate transaction values within given categories
     */
    public function generateCategoryTreeStatisticsWithinPeriod(string $type, CarbonInterface $from, CarbonInterface $to, ?Account $account = null): array
    {
        $repo = $type === TransactionInterface::INCOME
            ? $this->em->getRepository(IncomeCategory::class)
            : $this->em->getRepository(ExpenseCategory::class);

        $tree = $repo->generateCategoryTree();

        foreach($tree as $rootCategory) {
            $transactions = $this->generateTransactionList(
                $from,
                $to,
                [$type],
                [$rootCategory['name']],
                $account ? [$account->getName()] : []
            );

            foreach($transactions as $transaction) {
                $this->updateValueInCategoryTree($tree, $transaction->getCategory()->getName(), $transaction->getValue());
            }
        }

        $this->calculateTotalCategoryValueInCategoryTree($tree);

        return $tree;
    }

    #[ArrayShape(['min' => "array", 'max' => "array"])]
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
            $sum = $this->sumTransactions(
                $this->generateExpenseTransactionList(
                    $date->startOfMonth(),
                    $date->copy()->endOfMonth(),
                    [$category]
                )
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
        $accounts = $this->accountRepo->findAll();

        /** @var Account $account */
        foreach($accounts as $account) {
            $transactions = $this->generateTransactionList($from, $to, [TransactionInterface::EXPENSE], [], [$account->getName()]);
            $amount = $this->sumTransactions($transactions, $account->getCurrency());
            $value = $this->sumTransactions($transactions);

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
                $this->generateTransactionList($start, $end, null, [$name])
            );
        }

        return $result;
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
                'value' => $this->sumTransactions($filteredTransactions),
            ];
        }

        return $result;
    }

    /**
     * Calculate total amount of assets
     */
    public function calculateTotalAssets(): float
    {
        $accounts = $this->accountRepo->findAll();
        $sum = 0;

        foreach($accounts as $account) {
            $sum += $account->getValue();
        }

        return $sum;
    }

    public function calculateExpenseWithinPeriod(CarbonInterface $from, CarbonInterface $to, ?array $categories = [], ?array $accounts = [], ?array $excludedCategories = []): float
    {
        return $this->sumTransactions(
            $this->generateTransactionList(
                $from,
                $to,
                [TransactionInterface::EXPENSE],
                $categories,
                $accounts,
                $excludedCategories
            )
        );
    }

    public function calculateIncomeWithinPeriod(CarbonInterface $from, CarbonInterface $to, ?array $categories = [], ?array $accounts = [], ?array $excludedCategories = []): float
    {
        return $this->sumTransactions(
            $this->generateTransactionList(
                $from,
                $to,
                [TransactionInterface::INCOME],
                $categories,
                $accounts,
                $excludedCategories
            )
        );
    }

    public function calculateAverage(CarbonInterface $from, CarbonInterface $to, string $timeframe, string $type): float
    {
        $transactions = $this->generateTransactionList($from, $to, [$type]);
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

    public function calculateTotalValueOfTransactions(Query $query): float
    {
        $allTransactionsFiltered = $query
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getResult();

        return $this->roundAmountToPrecision(
            $this->sumMixedTransactions($allTransactionsFiltered)
        );
    }

    /**
     * Calculates the sum of expenses made today converted to base currency
     */
    public function calculateTodayExpenses(): float
    {
        $todayExpenses = $this
            ->expenseRepo
            ->getOnGivenDay(Carbon::today());

        return $this->sumTransactions($todayExpenses);
    }

    /**
     * Calculate the sum of total month incomes
     */
    public function calculateMonthIncomes(CarbonInterface $month): float
    {
        return $this->calculateIncomeWithinPeriod(
            $month->startOfMonth(),
            $month->endOfMonth()
        );
    }

    /**
     * Calculate amount of money spent on rent and utilities within given period
     */
    public function calculateRentExpensesWithinPeriod(CarbonInterface $from, CarbonInterface $to): float
    {
        $rentCategories = $this->getTypedCategoriesWithChildren([TransactionInterface::EXPENSE], [ExpenseCategory::CATEGORY_RENT, ExpenseCategory::CATEGORY_UTILITIES]);

        $sum = $this->sumTransactions(
            $this->expenseRepo->findWithinPeriod($from, $to, true, $rentCategories)
        );

        return $this->roundAmountToPrecision($sum);
    }

    /**
     * Calculate amount of money spent on food within given period
     */
    public function calculateFoodExpensesWithinPeriod(CarbonInterface $from, CarbonInterface $to, bool $generateForPreviousPeriod = false): float|array
    {
        $foodCategories = $this->getTypedCategoriesWithChildren([TransactionInterface::EXPENSE], [ExpenseCategory::CATEGORY_FOOD]);

        $sumForCurrentPeriod = $this->sumTransactions(
            $this->expenseRepo->findWithinPeriod(
                $from,
                $to,
                true,
                $foodCategories
            )
        );

        if($generateForPreviousPeriod) {
            $previousPeriod = $this->generatePreviousTimeperiod($from, $to);

            $sumForPreviousPeriod = $this->sumTransactions(
                $this->expenseRepo->findWithinPeriod(
                    $previousPeriod->getStartDate(),
                    $previousPeriod->getEndDate(),
                    true,
                    $foodCategories
                )
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
        $expenseSum = $this->calculateExpenseWithinPeriod($from, $to, null, null, [ExpenseCategory::CATEGORY_RENT]);
        $average = $expenseSum / $to->endOfDay()->diffInDays($from->startOfDay());

        return $this->roundAmountToPrecision($average);
    }

    public function roundAmountToPrecision(float $amount, int $precision = self::DECIMAL_PRECISION): float
    {
        return round($amount, $precision);
    }

    public function sumMixedTransactions(array $transactions): float
    {
        return array_reduce($transactions, static function ($carry, TransactionInterface $transaction) {
            return $carry + ($transaction->getValue() * ($transaction->isExpense() ? -1 : 1));
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
     * Converts entity's value to a specified currency
     */
    public function convertTo(ValuableInterface $entity, string $to): float
    {
        return $this->fixerService->convertTo(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $to,
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    /**
     * Converts entity's value to a user's base currency
     */
    public function convertToBaseCurrency(ValuableInterface $entity): float
    {
        return $this->fixerService->convertToBaseCurrency(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    public function getTypedCategoriesWithChildren(array $types, ?array $categories): array
    {
        $result = [];

        foreach($types as $type) {
            $repo = $this->em
                ->getRepository($type === TransactionInterface::EXPENSE ? ExpenseCategory::class : IncomeCategory::class);

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
                $child['value'] = array_key_exists('value', $child) ? $child['value'] + $value : $value;
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
                $child['total'] = array_key_exists('value', $child) ? $child['value'] : 0;
            } else {
                $this->calculateTotalCategoryValueInCategoryTree($child['children']);

                $childrenTotal = array_reduce($child['children'], static function (float $carry, array $el) {
                    return array_key_exists('total', $el) ? $carry + $el['total'] : $carry;
                }, 0);
                $child['total'] = array_key_exists('value', $child) ? $child['value'] + $childrenTotal : $childrenTotal;
            }
        }
    }
}
