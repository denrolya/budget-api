<?php

namespace App\Service;

use App\DTO\CurrentPrevious;
use App\Entity\Account;
use App\Entity\AccountLogEntry;
use App\Entity\Category;
use App\Entity\Debt;
use App\Entity\ExecutableInterface;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Entity\ValuableInterface;
use App\Pagination\Paginator;
use App\Repository\AccountLogEntryRepository;
use App\Repository\AccountRepository;
use App\Repository\ExpenseRepository;
use App\Repository\IncomeRepository;
use App\Repository\TransactionRepository;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use GuzzleHttp\Exception\GuzzleException;

class AssetsManager
{
    protected const DECIMAL_PRECISION = 2;

    private EntityManagerInterface $em;

    private FixerService $fixerService;

    private TransactionRepository $transactionRepo;

    private ExpenseRepository $expenseRepo;

    private IncomeRepository $incomeRepo;

    private AccountRepository $accountRepo;

    private AccountLogEntryRepository $accountLogsRepo;

    public function __construct(EntityManagerInterface $em, FixerService $fixerService)
    {
        $this->em = $em;
        $this->fixerService = $fixerService;
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
        $this->expenseRepo = $this->em->getRepository(Expense::class);
        $this->incomeRepo = $this->em->getRepository(Income::class);
        $this->accountRepo = $this->em->getRepository(Account::class);
        $this->accountLogsRepo = $this->em->getRepository(AccountLogEntry::class);
    }

    /**
     * @param array $types
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param array $accounts
     * @param array $categories
     * @param array $excludedCategories
     * @param bool $withChildCategories
     * @param int $limit
     * @param int $offset
     * @param string $orderField
     * @param string $order
     * @return array|bool
     */
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
    )
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

    /**
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param null|string[] $types
     * @param null|string[] $categories
     * @param null|string[] $accounts
     * @param null|string[] $excludedCategories
     * @param bool $withChildCategories
     * @param bool $onlyDrafts
     * @param string $orderField
     * @param string $order
     *
     * @return array|bool
     */
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
    )
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
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param null|array $categories
     * @param null|array $accounts
     * @param null|array $excludedCategories
     * @param bool $withChildCategories
     * @param bool $onlyDrafts
     * @param string $orderField
     * @param string $order
     * @return array|bool
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
    )
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
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param null|array $categories
     * @param null|array $accounts
     * @param null|array $excludedCategories
     * @param bool $withChildCategories
     * @param bool $onlyDrafts
     * @param string $orderField
     * @param string $order
     * @return array|bool
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
    )
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
     * Expenses and incomes on a fixed timeframe with fixed step
     *
     * @param CarbonPeriod $period
     * @param string $timeframe
     *
     * @return array
     */
    public function generateMoneyFlowStatistics(CarbonPeriod $period, string $timeframe = '1 month'): array
    {
        $result = [];

        foreach($period as $date) {
            $expense = $this->sumTransactions(
                $this->expenseRepo->findWithinPeriod(
                    $date->copy()->startOf($timeframe),
                    $date->copy()->endOf($timeframe)
                ));

            $income = $this->sumTransactions($this->incomeRepo->findWithinPeriod(
                $date->copy()->startOf($timeframe),
                $date->copy()->endOf($timeframe)
            ));

            $result[] = [
                'date' => $date->timestamp,
                'expense' => -$expense,
                'income' => $income,
            ];
        }

        return $result;
    }

    /**
     * Expenses & Incomes within given period with floating step
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     *
     * @return array
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
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param array|null $accounts
     * @return array
     */
    public function generateAccountsLogs(CarbonInterface $from, CarbonInterface $to, ?array $accounts = null): array
    {
        $numberOfItems = 80;

        $result = [];
        if($accounts === null) {
            $accounts = $this->accountRepo->findBy([
                'archivedAt' => null,
            ]);
        }

        foreach($accounts as $account) {
            $logs = $this
                ->accountLogsRepo
                ->findWithinPeriod($from, $to, $numberOfItems);

            $result[] = [
                'account' => $account->getId(),
                'logs' => $logs,
            ];
        }

        return $result;
    }

    /**
     * Using the structure provided by ExpenseCategoryRepository::generateCategoryTree
     * calculate transaction values within given categories
     *
     * @param string $type
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param Account|null $account
     * @return array
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

    /**
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param string $category
     * @return array
     * @throws \Exception
     */
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
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return array
     */
    public function generateAccountExpenseDistributionStatistics(CarbonInterface $from, CarbonInterface $to): array
    {
        $result = [];
        $accounts = $this->accountRepo->findAll();

        /** @var Account $account */
        foreach($accounts as $account) {
            $transactions = $this->generateTransactionList($from, $to, [TransactionInterface::EXPENSE], [], [$account->getName()]);
            $amount = $this->sumTransactions($transactions, $account->getCurrencyCode());
            $value = $this->sumTransactions($transactions);

            if(!$value) {
                continue;
            }

            $result[] = [
                'account' => [
                    'name' => $account->getName(),
                    'symbol' => $account->getCurrency()->getSymbol(),
                    'color' => $account->getColor(),
                ],
                'amount' => $amount,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * Sums categories' transactions and groups them by timeframe within given period
     *
     * @param CarbonPeriod $period
     * @param string $timeFrame
     * @param string[] $categories
     * @return array|null
     */
    public function generateCategoriesOnTimelineStatistics(CarbonPeriod $period, string $timeFrame, ?array $categories): ?array
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

            $result[$name] = $this->calculateTransactionsByTimeframe(
                $period,
                $timeFrame,
                $this->generateTransactionList($start, $end, null, [$name])
            );
        }

        return $result;
    }

    /**
     * @param CarbonPeriod $period
     * @param string $timeFrame
     * @param array $transactions
     * @return array
     */
    public function calculateTransactionsByTimeframe(CarbonPeriod $period, string $timeFrame = 'day', array $transactions = []): array
    {
        $result = [];
        foreach($period as $date) {
            $filteredTransactions = array_filter(
                $transactions,
                static function (TransactionInterface $transaction) use ($date, $timeFrame) {
                    return $transaction->getExecutedAt()->startOfDay()->between(
                        $date,
                        $date->copy()->add($timeFrame)->subDay()->endOf('day')
                    );
                });

            $result[$date->toDateString()] = $this->sumTransactions($filteredTransactions);
        }

        return $result;
    }

    /**
     * Calculate total amount of assets
     *
     * @return float
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

    /**
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param array|null $categories
     * @param array|null $accounts
     * @param array|null $excludedCategories
     * @return float
     */
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

    /**
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param array|null $categories
     * @param array|null $accounts
     * @param array|null $excludedCategories
     * @return float
     */
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

    /**
     * @param CarbonInterface $date
     * @return float
     */
    public function calculateQuarterAverageWeeklyExpense(CarbonInterface $date): float
    {
        $quarterStart = $date->copy()->firstOfQuarter();
        $quarterEnd = $date->copy()->lastOfQuarter();

        $expenses = $this->expenseRepo->findWithinPeriod($quarterStart, $quarterEnd);
        $sum = $this->sumTransactions($expenses);
        $average = $sum / ($quarterStart->diffInWeeks($quarterEnd) + 1);

        return $this->roundAmountToPrecision($average);
    }

    /**
     * @param CarbonInterface $date
     * @return float
     */
    public function calculateAnnualAverageMonthExpense(CarbonInterface $date): float
    {
        $yearStart = $date->copy()->firstOfYear();
        $yearEnd = $date->copy()->lastOfYear();

        $expenses = $this->expenseRepo->findWithinPeriod($yearStart, $yearEnd);
        $sum = $this->sumTransactions($expenses);
        $average = $sum / 12;

        return $this->roundAmountToPrecision($average);
    }

    /**
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param string $tf
     * @param string $type
     * @return float
     */
    public function calculateAverage(CarbonInterface $from, CarbonInterface $to, string $tf, string $type): float
    {
        $transactions = $this->generateTransactionList($from, $to, [$type]);
        $period = new CarbonPeriod($from, "1 $tf", $to);

        $byDate = [];

        foreach($period as $date) {
            $transactionsByDate = array_filter($transactions, static function (TransactionInterface $transaction) use ($date, $tf) {
                return $transaction->getExecutedAt()->startOfDay()->between(
                    $date,
                    $date->copy()->endOf($tf)
                );
            });
            $byDate[$date->toDateString()] = count($transactionsByDate)
                ? $this->sumTransactions($transactionsByDate) / count($transactionsByDate)
                : 0;
        }

        return array_sum(array_values($byDate)) / $period->count();
    }

    /**
     * @param TransactionInterface[] $transactions
     * @return float
     */
    public function calculateAverageTransaction(array $transactions = []): float
    {
        $sum = array_reduce($transactions, static function (float $acc, TransactionInterface $transaction) {
            return $acc + $transaction->getValue();
        }, 0);

        return $sum / (count($transactions) ?: 1);
    }

    /**
     * @param Query $query
     * @return float
     */
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
     *
     * @return float
     */
    public function calculateTodayExpenses(): float
    {
        $todayExpenses = $this
            ->expenseRepo
            ->getOnGivenDay(Carbon::today());

        return $this->roundAmountToPrecision($this->sumTransactions($todayExpenses));
    }

    /**
     * Calculate the sum of total month incomes
     *
     * @param CarbonInterface $month
     * @return float
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
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return float
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
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @param bool $generateForPreviousPeriod
     * @return CurrentPrevious|float
     */
    public function calculateFoodExpensesWithinPeriod(CarbonInterface $from, CarbonInterface $to, bool $generateForPreviousPeriod = false)
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

            return new CurrentPrevious($sumForCurrentPeriod, $sumForPreviousPeriod);
        }

        return $sumForCurrentPeriod;
    }

    /**
     * Calculate average daily expense for given period converted to base currency
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return float
     */
    public function calculateAverageDailyExpenseWithinPeriod(CarbonInterface $from, CarbonInterface $to): float
    {
        $expenseSum = $this->calculateExpenseWithinPeriod($from, $to, null, null, [ExpenseCategory::CATEGORY_RENT]);
        $average = $expenseSum / $to->endOfDay()->diffInDays($from->startOfDay());

        return $this->roundAmountToPrecision($average);
    }

    /**
     * @param Debt $debt
     * @return float
     */
    public function calculateDebtBalance(Debt $debt): float
    {
        return $this->roundAmountToPrecision(
            -1 * $this->sumMixedTransactions(
                $debt->getTransactions()
            )
        );
    }

    /**
     * @param int|float $amount
     * @param int $precision
     * @return float
     */
    public function roundAmountToPrecision(float $amount, int $precision = self::DECIMAL_PRECISION): float
    {
        return round($amount, $precision);
    }

    /**
     * @param TransactionInterface[] $transactions
     * @return float
     */
    public function sumMixedTransactions(array $transactions): float
    {
        return array_reduce($transactions, static function ($carry, TransactionInterface $transaction) {
            return $carry + ($transaction->getValue() * ($transaction->isExpense() ? -1 : 1));
        }, 0);
    }

    /**
     * Sum given transactions value in user's base currency
     *
     * @param array $transactions
     * @param null|string $currency
     * @return float
     */
    public function sumTransactions(array $transactions, ?string $currency = null): float
    {
        return array_reduce($transactions, static function ($carry, TransactionInterface $transaction) use ($currency) {
            return $carry + $transaction->getConvertedValue($currency);
        }, 0);
    }

    /**
     * Generates array of converted values to all base fiat currencies
     *
     * @param ValuableInterface $entity
     * @return array
     * @throws GuzzleException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function convert(ValuableInterface $entity): array
    {
        return $this->fixerService->convert(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrencyCode(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    /**
     * Converts entity's value to a specified currency
     *
     * @param ValuableInterface $entity
     * @param string $to
     * @return float
     * @throws GuzzleException
     */
    public function convertTo(ValuableInterface $entity, string $to): float
    {
        return $this->fixerService->convertTo(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrencyCode(),
            $to,
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    /**
     * Converts entity's value to a user's base currency
     *
     * @param ValuableInterface $entity
     * @return bool|float|int
     * @throws GuzzleException
     */
    public function convertToBaseCurrency(ValuableInterface $entity)
    {
        return $this->fixerService->convertToBaseCurrency(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrencyCode(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );
    }

    /**
     * @param array $types
     * @param array|null $categories
     * @return array
     */
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
     *
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return CarbonPeriod
     */
    public function generatePreviousTimeperiod(CarbonInterface $from, CarbonInterface $to): CarbonPeriod
    {
        return ($from->isSameDay($from->copy()->startOfMonth()) && $to->isEndOfDay($to->copy()->endOfMonth()))
            ? new CarbonPeriod(
                $from->copy()->sub('month', $this->diffInMonths($from, $to)),
                $from->copy()->sub('days', 1)
            )
            : new CarbonPeriod(
                $from->copy()->sub('days', $to->diffInDays($from) + 1),
                $from->copy()->sub('days', 1)
            );
    }

    /**
     * This function calculates proper difference in months between to CarbonInterface dates,
     * Carbon implementation is sometimes wrong
     *
     * @param CarbonInterface $startDate
     * @param CarbonInterface|null $endDate
     * @return int
     */
    private function diffInMonths(CarbonInterface $startDate, CarbonInterface $endDate = null)
    {
        $endDate = $endDate ?: Carbon::now($startDate->getTimezone());

        $months = 0;
        if($startDate->eq($endDate)) {
            return 0;
        }

        if($startDate->lt($endDate)) {
            $diff_date = $startDate->copy();
            while($diff_date->lt($endDate)) {
                $months++;
                $diff_date->addMonth();
            }

            return $months;
        }

        $endDate = $endDate->copy();
        while($endDate->lt($startDate)) {
            $months++;
            $endDate->addMonth();
        }

        return $months;
    }

    /**
     * Calculate expenses using exchange rates from Fixer. The sum is converted into base currency.
     *
     * @param array $expenses
     * @param ExpenseCategory|null $rootCategory
     *
     * @return array
     */
    private function calculateExpensesByCategory(array $expenses, ?ExpenseCategory $rootCategory): array
    {
        $categories = $rootCategory === null
            ? $this->getTypedCategoriesWithChildren(
                [TransactionInterface::EXPENSE],
                $rootCategory ? [$rootCategory->getName()] : null
            )
            : array_merge([$rootCategory->getName()], $rootCategory->getFirstDescendantsNames());

        $result = array_map(static function ($category) {
            return [
                'name' => $category,
                'amount' => 0,
            ];
        }, $categories);

        /** @var Expense $expense */
        foreach($expenses as $expense) {
            $expenseCategory = $expense->getCategory();

            $key = array_search($rootCategory
                ? $expenseCategory->directAncestorInRootCategory($rootCategory)->getName()
                : $expense->getRootCategory()->getName(), array_column($result, 'name'), true);

            $result[$key]['amount'] += $expense->getValue();
        }

        return array_map(function ($e) {
            $e['amount'] = $this->roundAmountToPrecision($e['amount']);

            return $e;
        }, $result);
    }

    /**
     * Given category tree generated with ExpenseCategoryRepository::generateCategoryTree
     * find the needle category and update its value
     *
     * @param array $haystack
     * @param string $needle
     * @param float $value
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
     *
     * @param array $haystack
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
