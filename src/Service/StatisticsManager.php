<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\IncomeCategoryRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

// TODO: extract insights methods (computeBudgetInsights, computeOutliers, computeTrends,
//       computeSeasonalPatterns) into a dedicated BudgetInsightsCalculator when splitting StatisticsManager
final class StatisticsManager
{
    private const OUTLIER_MAD_THRESHOLD = 5.0;

    private const OUTLIER_MINIMUM_GROUP_SIZE = 3;

    private const OUTLIER_MAXIMUM_RESULTS = 20;

    private const TREND_LOOKBACK_MONTHS = 6;

    private const TREND_MINIMUM_ACTIVE_MONTHS = 3;

    private const TREND_CHANGE_THRESHOLD_PERCENT = 10.0;

    private const SEASONAL_LOOKBACK_MONTHS = 24;

    private const SEASONAL_DEVIATION_THRESHOLD = 0.3;

    /**
     * Lazily populated once per request from buildDescendantMap().
     *
     * @var array<int, list<int>>|null
     */
    private ?array $descendantMap = null;

    /**
     * Lazily populated once per request from buildParentMap().
     *
     * @var array<int, int|null>|null
     */
    private ?array $parentMap = null;

    public function __construct(
        private readonly AssetsManager $assetsManager,
        private readonly TransactionRepository $transactionRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly ExpenseCategoryRepository $expenseCategoryRepository,
        private readonly IncomeCategoryRepository $incomeCategoryRepository,
    ) {
    }

    /**
     * Generates transactions statistics grouped by types. With given interval also grouped by date interval.
     * Single DB query for the full range; PHP interval bucketing in one pass.
     *
     * @param int[]|null $categories
     * @param int[]|null $accounts
     *
     * @return array<int, array{after: int, before: int, expense: float, income: float}>
     */
    public function calculateTransactionsValueByPeriod(
        CarbonPeriod $period,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
    ): array {
        $allTransactions = $this->transactionRepository->getList(
            after: $period->getStartDate(),
            before: $period->getEndDate(),
            type: $type,
            categories: $categories,
            accounts: $accounts,
        );

        $bounds = $this->buildPeriodBounds($period);
        $bucketed = $this->bucketTransactionsByPeriod($allTransactions, $bounds);

        $result = [];
        foreach ($bounds as $index => $bound) {
            $expenses = [];
            $incomes = [];
            foreach ($bucketed[$index] as $transaction) {
                if (Transaction::EXPENSE === $transaction->getType()) {
                    $expenses[] = $transaction;
                } else {
                    $incomes[] = $transaction;
                }
            }

            $result[] = [
                'after' => (int) $bound['after']->timestamp,
                'before' => (int) $bound['before']->timestamp,
                'expense' => $this->sumNetOfCompensations($expenses),
                'income' => $this->assetsManager->sumTransactions($incomes),
            ];
        }

        return $result;
    }

    /**
     * Builds category tree and sets value/total on each node.
     * Uses a pre-built descendant ID map instead of recursive getDescendantsFlat() / isChildOf() calls.
     *
     * Expense values are net of any linked compensations (contra-expense model): each Expense
     * entity contributes getConvertedValue() minus the sum of its getCompensations() collection.
     *
     * @param Transaction[] $transactions
     * @param Category[] $categories
     *
     * @return Category[] root categories with nested children, each with value/total set
     */
    public function generateCategoryTreeWithValues(
        array $transactions,
        ?string $type = null,
        array $categories = [],
    ): array {
        $categories = [] !== $categories ? $categories : $this->getRootCategories($type);

        if ([] === $categories || [] === $transactions) {
            return $categories;
        }

        // Index once; shared across all recursion levels.
        $transactionsByCatId = [];
        foreach ($transactions as $transaction) {
            $categoryId = $transaction->getCategory()->getId();
            \assert(null !== $categoryId);
            $transactionsByCatId[$categoryId][] = $transaction;
        }

        $this->hydrateCategoryValues(
            $categories,
            $transactionsByCatId,
            $this->getDescendantMap(),
        );

        return $categories;
    }

    /**
     * Generates account value distribution statistics within given transactions array.
     *
     * @param Transaction[] $transactions
     *
     * @return array<int, array{account: Account, amount: float, value: float}>
     */
    public function generateAccountDistributionStatistics(array $transactions): array
    {
        $result = [];
        $accountExpenses = [];

        foreach ($transactions as $transaction) {
            $accountId = $transaction->getAccount()->getId();
            if (!isset($accountExpenses[$accountId])) {
                $accountExpenses[$accountId] = [
                    'account' => $transaction->getAccount(),
                    'currency' => $transaction->getAccount()->getCurrency(),
                    'transactions' => [],
                ];
            }
            $accountExpenses[$accountId]['transactions'][] = $transaction;
        }

        foreach ($accountExpenses as $accountData) {
            $amount = $this->assetsManager->sumTransactions($accountData['transactions'], $accountData['currency']);
            $value = $this->assetsManager->sumTransactions($accountData['transactions']);

            if (!$value) {
                continue;
            }

            $result[] = [
                'account' => $accountData['account'],
                'amount' => $amount,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * Sums categories' transactions and groups them by timeframe within given period.
     *
     * @param int[]|null $categories
     * @param Transaction[] $transactions
     *
     * @return array<string, array<int, array{date: int, value: float}>>|null category name → list of {date, value} for each interval
     */
    public function generateCategoriesOnTimelineStatistics(
        CarbonPeriod $period,
        ?array $categories,
        array $transactions,
    ): ?array {
        if (null === $categories || [] === $categories) {
            return null;
        }

        $result = [];
        $start = $period->getStartDate();
        $end = $period->getEndDate();
        \assert(null !== $end);

        $descendantMap = $this->getDescendantMap();

        // Batch-fetch all requested categories in a single query (avoids N+1).
        $categoryEntities = $this->categoryRepository->findBy(['id' => $categories]);
        $categoriesById = [];
        foreach ($categoryEntities as $category) {
            $categoriesById[$category->getId()] = $category;
        }

        // Index by category ID, pre-filtered to period bounds.
        $transactionsByCatId = [];
        foreach ($transactions as $transaction) {
            $executedAt = $transaction->getExecutedAt();
            \assert(null !== $executedAt);
            if ($executedAt->isBetween($start, $end)) {
                $categoryId = $transaction->getCategory()->getId();
                \assert(null !== $categoryId);
                $transactionsByCatId[$categoryId][] = $transaction;
            }
        }

        foreach ($categories as $categoryId) {
            $category = $categoriesById[$categoryId] ?? null;
            if (null === $category) {
                continue;
            }

            $descendantIds = $descendantMap[$categoryId] ?? [$categoryId];
            $categoryName = $category->getName();
            \assert(null !== $categoryName);
            $result[$categoryName] = $this->sumTransactionsByDateInterval(
                $this->collectTransactionsForDescendants($descendantIds, $transactionsByCatId),
                $period,
            );
        }

        return $result;
    }

    /**
     * Find transaction category that holds the biggest cumulative value.
     * Used for: mainIncomeSource.
     *
     * @param Transaction[] $transactions
     *
     * @return array{name: string, value: float}|null
     */
    public function generateTopValueCategoryStatistics(array $transactions): ?array
    {
        if ([] === $transactions) {
            return null;
        }

        $rootCategories = $this->categoryRepository->findBy([
            'root' => null,
            'isAffectingProfit' => true,
        ]);

        $descendantMap = $this->getDescendantMap();
        $max = 0;
        $result = null;

        $transactionsByCatId = [];
        foreach ($transactions as $transaction) {
            $categoryId = $transaction->getCategory()->getId();
            \assert(null !== $categoryId);
            $transactionsByCatId[$categoryId][] = $transaction;
        }

        foreach ($rootCategories as $category) {
            $categoryId = $category->getId();
            \assert(null !== $categoryId);
            $descendantIds = $descendantMap[$categoryId] ?? [$categoryId];

            $subtreeTransactions = [];
            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $t) {
                    $subtreeTransactions[] = $t;
                }
            }

            $value = abs($this->assetsManager->sumTransactions($subtreeTransactions));

            if ($value > $max) {
                $max = $value;
                $categoryName = $category->getName();
                \assert(null !== $categoryName);
                $result = [
                    'name' => $categoryName,
                    'value' => $max,
                ];
            }
        }

        return $result;
    }

    /**
     * @param Transaction[] $transactionsOrdered
     *
     * @return array<int, array{name: string, values: array<string, float>}>
     */
    public function generateTransactionsValueByCategoriesByWeekdays(array $transactionsOrdered): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $result = array_map(static fn ($day) => ['name' => $day, 'values' => []], $days);

        if ([] === $transactionsOrdered) {
            return $result;
        }

        $rootCategories = $this->expenseCategoryRepository->findRootCategories(['name' => 'ASC']);
        $descendantMap = $this->getDescendantMap();

        $transactionsByCatId = [];
        foreach ($transactionsOrdered as $transaction) {
            $categoryId = $transaction->getCategory()->getId();
            \assert(null !== $categoryId);
            $transactionsByCatId[$categoryId][] = $transaction;
        }

        foreach ($rootCategories as $category) {
            $categoryName = $category->getName();
            \assert(null !== $categoryName);
            $categoryId = $category->getId();
            \assert(null !== $categoryId);
            $descendantIds = $descendantMap[$categoryId] ?? [$categoryId];

            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $transaction) {
                    $executedAt = $transaction->getExecutedAt();
                    \assert(null !== $executedAt);
                    $weekday = $executedAt->dayOfWeekIso - 1;
                    $result[$weekday]['values'][$categoryName] =
                        ($result[$weekday]['values'][$categoryName] ?? 0) + $transaction->getValue();
                }
            }
        }

        $lastTransaction = end($transactionsOrdered);
        $lastExecutedAt = $lastTransaction->getExecutedAt();
        \assert(null !== $lastExecutedAt);
        $firstExecutedAt = $transactionsOrdered[0]->getExecutedAt();
        \assert(null !== $firstExecutedAt);

        foreach ($result as $index => &$weekdayData) {
            $weekdaysCount = $this->countWeekdaysBetweenDates(
                $lastExecutedAt,
                $firstExecutedAt,
                $index,
            );

            if ($weekdaysCount > 0) {
                $weekdayData['values'] = array_map(static fn ($value) => $value / $weekdaysCount, $weekdayData['values']);
            }
        }

        return $result;
    }

    /**
     * @param Transaction[] $transactions
     *
     * @return array<int, array{date: int, value: float}>
     */
    public function sumTransactionsByDateInterval(array $transactions, CarbonPeriod $period): array
    {
        $bounds = $this->buildPeriodBounds($period, false);
        $bucketed = $this->bucketTransactionsByPeriod($transactions, $bounds);

        $result = [];
        foreach ($bounds as $index => $bound) {
            $result[] = [
                'date' => (int) $bound['after']->timestamp,
                'value' => $this->assetsManager->sumTransactions($bucketed[$index]),
            ];
        }

        return $result;
    }

    /**
     * @param Transaction[] $transactions
     *
     * @return array<int, array{date: int, value: float}>
     */
    public function averageByPeriod(array $transactions, CarbonPeriod $period): array
    {
        $bounds = $this->buildPeriodBounds($period, false);
        $bucketed = $this->bucketTransactionsByPeriod($transactions, $bounds);

        $result = [];
        foreach ($bounds as $index => $bound) {
            $transactionsWithinPeriod = $bucketed[$index];
            $result[] = [
                'date' => (int) $bound['after']->timestamp,
                'value' => 0 !== \count($transactionsWithinPeriod) ? $this->assetsManager->sumTransactions(
                    $transactionsWithinPeriod,
                ) / \count($transactionsWithinPeriod) : 0,
            ];
        }

        return $result;
    }

    /**
     * @return Category[]
     */
    private function getRootCategories(?string $type): array
    {
        $repository = match ($type) {
            Category::EXPENSE_CATEGORY_TYPE => $this->expenseCategoryRepository,
            Category::INCOME_CATEGORY_TYPE => $this->incomeCategoryRepository,
            default => $this->categoryRepository,
        };

        return $repository->findRootCategories();
    }

    /**
     * Lazily builds and caches a map of categoryId → [self + all descendant ids] for the current request.
     * Single DB query; eliminates all getDescendantsFlat() / isChildOf() recursive loads.
     *
     * @return array<int, list<int>>
     */
    private function getDescendantMap(): array
    {
        if (null === $this->descendantMap) {
            $this->descendantMap = $this->categoryRepository->buildDescendantMap();
        }

        return $this->descendantMap;
    }

    /**
     * Lazily builds and caches a map of categoryId → parentId (null for roots) for the current request.
     *
     * @return array<int, int|null>
     */
    private function getParentMap(): array
    {
        if (null === $this->parentMap) {
            $this->parentMap = $this->categoryRepository->buildParentMap();
        }

        return $this->parentMap;
    }

    /**
     * Recursively hydrates setValue/setTotal on each category using a pre-built descendant ID map.
     * Eliminates isChildOf() — no per-transaction recursive DB queries.
     *
     * Expense values are net of linked compensations via $expense->getCompensations() (Doctrine
     * EXTRA_LAZY collection). Income categories are unaffected.
     *
     * @param Category[] $categories
     * @param array<int, Transaction[]> $transactionsByCatId
     * @param array<int, list<int>> $descendantMap
     */
    private function hydrateCategoryValues(
        array $categories,
        array $transactionsByCatId,
        array $descendantMap,
    ): void {
        foreach ($categories as $category) {
            $catId = $category->getId();
            $descendantIds = $descendantMap[$catId] ?? [$catId];

            $category->setValue($this->sumNetOfCompensations($transactionsByCatId[$catId] ?? []));

            $subtree = [];
            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $transaction) {
                    $subtree[] = $transaction;
                }
            }
            $category->setTotal($this->sumNetOfCompensations($subtree));

            // Recurse into children — one EXTRA_LAZY SELECT per non-leaf, not per-transaction.
            if (\count($descendantIds) > 1 && $category->hasChildren()) {
                $this->hydrateCategoryValues(
                    $category->getChildren()->toArray(),
                    $transactionsByCatId,
                    $descendantMap,
                );
            }
        }
    }

    /**
     * Sums transaction converted values, reducing each Expense by the sum of its linked
     * compensations. Uses $expense->getCompensations() (EXTRA_LAZY Doctrine collection),
     * which fires one SELECT per Expense that has compensations.
     *
     * @param Transaction[] $transactions
     */
    private function sumNetOfCompensations(array $transactions): float
    {
        $total = $this->assetsManager->sumTransactions($transactions);
        foreach ($transactions as $transaction) {
            if ($transaction instanceof Expense) {
                $total -= $this->assetsManager->sumTransactions(
                    $transaction->getCompensations()->toArray(),
                );
            }
        }

        return $total;
    }

    /**
     * Counts how many times a given ISO weekday (0=Monday … 6=Sunday) appears in the
     * inclusive date range [$after, $before].
     */
    private function countWeekdaysBetweenDates(CarbonInterface $after, CarbonInterface $before, int $weekday): int
    {
        if ($weekday < 0 || $weekday > 6) {
            throw new InvalidArgumentException('Invalid weekday. Please provide a value between 0 and 6 (Monday to Sunday).');
        }

        $startDate = $after->copy()->startOfDay();
        $endDate = $before->copy()->startOfDay();

        if ($startDate->greaterThan($endDate)) {
            return 0;
        }

        $totalDays = $startDate->diffInDays($endDate) + 1;
        $fullWeeks = intdiv($totalDays, 7);
        $count = $fullWeeks;

        $remainder = $totalDays % 7;
        // Use ISO weekday (1=Mon…7=Sun), shifted to 0-based (0=Mon…6=Sun) to match $weekday.
        $startWeekday = $startDate->dayOfWeekIso - 1;

        for ($i = 0; $i < $remainder; ++$i) {
            if ((($startWeekday + $i) % 7) === $weekday) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{after: CarbonInterface, before: CarbonInterface, nextBound: CarbonInterface}>
     */
    private function buildPeriodBounds(CarbonPeriod $period, bool $includeLastDay = true): array
    {
        $endDate = $period->getEndDate();
        \assert(null !== $endDate);

        $dates = $period->toArray();
        $count = \count($dates);
        $bounds = [];

        foreach ($dates as $index => $after) {
            $isLast = $index === $count - 1;
            $before = $isLast ? $endDate : $dates[$index + 1]->copy()->subSecond();
            $nextBound = $isLast
                ? ($includeLastDay ? $endDate->copy()->addDay()->startOfDay() : $endDate)
                : $dates[$index + 1];

            if ($after->equalTo($nextBound)) {
                continue;
            }

            $bounds[] = [
                'after' => $after,
                'before' => $before,
                'nextBound' => $nextBound,
            ];
        }

        return $bounds;
    }

    /**
     * @param Transaction[] $transactions
     * @param array<int, array{after: CarbonInterface, before: CarbonInterface, nextBound: CarbonInterface}> $bounds
     *
     * @return array<int, array<int, Transaction>>
     */
    private function bucketTransactionsByPeriod(array $transactions, array $bounds): array
    {
        $buckets = [];
        foreach (array_keys($bounds) as $index) {
            $buckets[$index] = [];
        }

        foreach ($transactions as $transaction) {
            $executedAt = $transaction->getExecutedAt();
            \assert(null !== $executedAt);
            foreach ($bounds as $index => $bound) {
                if ($executedAt->greaterThanOrEqualTo($bound['after']) && $executedAt->lessThan($bound['nextBound'])) {
                    $buckets[$index][] = $transaction;
                    break;
                }
            }
        }

        return $buckets;
    }

    /**
     * @param int[] $descendantIds
     * @param array<int, array<int, Transaction>> $transactionsByCatId
     *
     * @return array<int, Transaction>
     */
    private function collectTransactionsForDescendants(array $descendantIds, array $transactionsByCatId): array
    {
        $transactions = [];
        foreach ($descendantIds as $descId) {
            foreach ($transactionsByCatId[$descId] ?? [] as $transaction) {
                $transactions[] = $transaction;
            }
        }

        return $transactions;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Insights (outliers, trends, seasonal)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Lightweight summary for sidebar display: actual and planned totals.
     * Actuals use convertedValues from transactions; planned uses raw line amounts.
     *
     * @return array{budgetId: int, actualExpense: array<string, float>, actualIncome: array<string, float>, plannedExpense: array<string, float>, plannedIncome: array<string, float>}
     */
    public function computeBudgetSummary(Budget $budget, TransactionRepository $transactionRepository): array
    {
        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        $actuals = $transactionRepository->getActualsByCategoryForPeriod($start, $end);

        // Aggregate actuals by currency
        $actualExpense = [];
        $actualIncome = [];
        foreach ($actuals as $item) {
            foreach ($item['convertedValues'] as $currency => $values) {
                $actualExpense[$currency] = ($actualExpense[$currency] ?? 0.0) + $values['expense'];
                $actualIncome[$currency] = ($actualIncome[$currency] ?? 0.0) + $values['income'];
            }
        }

        // Aggregate planned by currency using envelope model:
        // If a root category has a line, it represents the total — skip children's lines.
        // If a root has no line, sum its children's lines instead.
        $linesByCategoryId = [];
        foreach ($budget->getLines() as $line) {
            $linesByCategoryId[$line->getCategory()->getId()] = $line;
        }

        $plannedExpense = [];
        $plannedIncome = [];
        foreach ($budget->getLines() as $line) {
            $category = $line->getCategory();
            $parentCategory = $category->getParent();

            // Skip categories that don't affect P&L (matches frontend useBudgetTotals filter)
            if (!$category->getIsAffectingProfit()) {
                continue;
            }

            // Envelope model: skip if ANY ancestor has its own line
            $ancestor = $parentCategory;
            $hasAncestorLine = false;
            while (null !== $ancestor) {
                if (isset($linesByCategoryId[$ancestor->getId()])) {
                    $hasAncestorLine = true;
                    break;
                }
                $ancestor = $ancestor->getParent();
            }
            if ($hasAncestorLine) {
                continue;
            }

            $currency = $line->getPlannedCurrency();
            $amount = $line->getPlannedAmount();

            if ($category->isExpense()) {
                $plannedExpense[$currency] = ($plannedExpense[$currency] ?? 0.0) + $amount;
            } else {
                $plannedIncome[$currency] = ($plannedIncome[$currency] ?? 0.0) + $amount;
            }
        }

        return [
            'budgetId' => (int) $budget->getId(),
            'actualExpense' => $actualExpense,
            'actualIncome' => $actualIncome,
            'plannedExpense' => $plannedExpense,
            'plannedIncome' => $plannedIncome,
        ];
    }

    /**
     * @return array{outliers: list<array<string, mixed>>, trends: list<array<string, mixed>>, seasonal: list<array<string, mixed>>}
     */
    public function computeBudgetInsights(Budget $budget, string $baseCurrency): array
    {
        return [
            'outliers' => $this->computeOutliers($budget, $baseCurrency),
            'trends' => $this->computeTrends($budget, $baseCurrency),
            'seasonal' => $this->computeSeasonalPatterns($budget, $baseCurrency),
        ];
    }

    /**
     * Finds statistically unusual expense transactions within the budget period using Median Absolute Deviation.
     * Groups transactions by category; flags those deviating beyond the MAD threshold.
     *
     * @return list<array{transactionId: int, categoryId: int, note: string|null, executedAt: string, amount: float, convertedAmount: float, median: float, deviation: float}>
     */
    private function computeOutliers(Budget $budget, string $baseCurrency): array
    {
        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        $transactions = $this->transactionRepository->getList(
            after: $start,
            before: $end,
            type: Transaction::EXPENSE,
            affectingProfitOnly: true,
            isDraft: false,
        );

        $groupedByRootCategory = $this->groupTransactionsByRootCategory($transactions);
        $outliers = [];

        foreach ($groupedByRootCategory as $categoryId => $categoryTransactions) {
            if (\count($categoryTransactions) < self::OUTLIER_MINIMUM_GROUP_SIZE) {
                continue;
            }

            $values = $this->extractConvertedAmounts($categoryTransactions, $baseCurrency);
            if (\count($values) < self::OUTLIER_MINIMUM_GROUP_SIZE) {
                continue;
            }

            $median = $this->calculateMedian($values);
            $mad = $this->calculateMad($values, $median);

            if ($mad <= 0.0) {
                continue;
            }

            $fence = self::OUTLIER_MAD_THRESHOLD * $mad;

            foreach ($categoryTransactions as $transaction) {
                $convertedAmount = $transaction->getConvertedValue($baseCurrency);
                if ($convertedAmount <= 0.0) {
                    continue;
                }

                $absoluteDeviation = abs($convertedAmount - $median);
                if ($absoluteDeviation <= $fence) {
                    continue;
                }

                $executedAt = $transaction->getExecutedAt();
                \assert(null !== $executedAt);

                $transactionId = $transaction->getId();
                \assert(null !== $transactionId);

                $outliers[] = [
                    'transactionId' => $transactionId,
                    'categoryId' => $categoryId,
                    'note' => $transaction->getNote(),
                    'executedAt' => $executedAt->format('Y-m-d'),
                    'amount' => $transaction->getAmount(),
                    'convertedAmount' => $convertedAmount,
                    'median' => $median,
                    'deviation' => round($absoluteDeviation / $mad, 1),
                ];
            }
        }

        usort($outliers, static fn (array $first, array $second): int => $second['deviation'] <=> $first['deviation']);

        return \array_slice($outliers, 0, self::OUTLIER_MAXIMUM_RESULTS);
    }

    /**
     * Detects spending trend direction per category by comparing recent vs older monthly averages.
     *
     * @return list<array{categoryId: int, direction: string, changePercent: float, recentAverage: float, olderAverage: float}>
     */
    private function computeTrends(Budget $budget, string $baseCurrency): array
    {
        $budgetStart = CarbonImmutable::instance($budget->getStartDate());
        $budgetEnd = CarbonImmutable::instance($budget->getEndDate());
        $windowEnd = $budgetStart->subDay()->endOfDay();
        $windowStart = $budgetStart->subMonths(self::TREND_LOOKBACK_MONTHS)->startOfMonth()->startOfDay();

        $byMonth = $this->transactionRepository->getConvertedMonthlyTotalsByCategory($windowStart, $windowEnd);
        $monthSlots = $this->buildTrendMonthSlots($budgetStart);
        $halfPoint = (int) floor(\count($monthSlots) / 2);

        // Only show trends for categories with confirmed expense activity in the budget period.
        // Uses getList() with the same filters the budget itself applies (non-draft, expense, affecting profit)
        // to avoid false positives from drafts or income transactions that the budget view ignores.
        $budgetExpenses = $this->transactionRepository->getList(
            after: $budgetStart->startOfDay(),
            before: $budgetEnd->endOfDay(),
            type: Transaction::EXPENSE,
            affectingProfitOnly: true,
            isDraft: false,
        );
        $activeCategoryIds = array_keys($this->groupTransactionsByRootCategory($budgetExpenses));

        $rolledUp = $this->rollUpMonthlyTotalsByCategory($byMonth, $baseCurrency);
        $trends = [];

        foreach ($rolledUp as $rootCategoryId => $rootData) {
            if (!\in_array($rootCategoryId, $activeCategoryIds, true)) {
                continue;
            }

            $rootTrend = $this->computeTrendForCategory($rootCategoryId, $rootData['totals'], $monthSlots, $halfPoint);

            if (null === $rootTrend) {
                continue;
            }

            $children = [];
            foreach ($rootData['children'] as $childCategoryId => $childTotals) {
                $childTrend = $this->computeTrendForCategory($childCategoryId, $childTotals, $monthSlots, $halfPoint);
                if (null !== $childTrend) {
                    $children[] = $childTrend;
                }
            }

            // When children have significant trends, emit them instead of the parent aggregate.
            // The root trend is redundant — it's just the sum of its children.
            if ([] !== $children) {
                array_push($trends, ...$children);
            } else {
                $trends[] = $rootTrend;
            }
        }

        usort($trends, static fn (array $first, array $second): int => abs($second['changePercent']) <=> abs($first['changePercent']));

        return $trends;
    }

    /**
     * Identifies categories where spending in the budget's calendar month historically deviates
     * from the overall monthly average (seasonal spikes or dips).
     *
     * @return list<array{categoryId: int, seasonalFactor: float, currentMonthHistoricalAverage: float, overallMonthlyAverage: float, sampleYears: int}>
     */
    private function computeSeasonalPatterns(Budget $budget, string $baseCurrency): array
    {
        $budgetStart = CarbonImmutable::instance($budget->getStartDate());
        $targetMonth = $budgetStart->format('m');
        $windowEnd = $budgetStart->subDay()->endOfDay();
        $windowStart = $budgetStart->subMonths(self::SEASONAL_LOOKBACK_MONTHS)->startOfMonth()->startOfDay();

        $byMonth = $this->transactionRepository->getConvertedMonthlyTotalsByCategory($windowStart, $windowEnd);
        $rolledUp = $this->rollUpMonthlyTotalsByCategory($byMonth, $baseCurrency);
        $seasonal = [];

        foreach ($rolledUp as $rootCategoryId => $rootData) {
            $rootSeasonal = $this->computeSeasonalForCategory($rootCategoryId, $rootData['totals'], $targetMonth);

            if (null === $rootSeasonal) {
                continue;
            }

            $children = [];
            foreach ($rootData['children'] as $childCategoryId => $childTotals) {
                $childSeasonal = $this->computeSeasonalForCategory($childCategoryId, $childTotals, $targetMonth);
                if (null !== $childSeasonal) {
                    $children[] = $childSeasonal;
                }
            }

            $rootSeasonal['children'] = $children;
            $seasonal[] = $rootSeasonal;
        }

        usort(
            $seasonal,
            static fn (array $first, array $second): int => abs($second['seasonalFactor'] - 1.0) <=> abs($first['seasonalFactor'] - 1.0),
        );

        return $seasonal;
    }

    /**
     * Groups transactions by their root (top-level) category using the parent map.
     * Transactions in child categories are aggregated into their root ancestor's group,
     * so outlier detection operates on the full category tree rather than individual leaves.
     *
     * @param Transaction[] $transactions
     *
     * @return array<int, Transaction[]>
     */
    private function groupTransactionsByRootCategory(array $transactions): array
    {
        $parentMap = $this->getParentMap();

        $grouped = [];
        foreach ($transactions as $transaction) {
            $categoryId = $transaction->getCategory()->getId();
            \assert(null !== $categoryId);

            $rootId = $categoryId;
            while (isset($parentMap[$rootId])) {
                $rootId = $parentMap[$rootId];
            }

            $grouped[$rootId][] = $transaction;
        }

        return $grouped;
    }

    /**
     * @param Transaction[] $transactions
     *
     * @return list<float>
     */
    private function extractConvertedAmounts(array $transactions, string $baseCurrency): array
    {
        $amounts = [];
        foreach ($transactions as $transaction) {
            $value = $transaction->getConvertedValue($baseCurrency);
            if ($value > 0.0) {
                $amounts[] = $value;
            }
        }

        return $amounts;
    }

    /**
     * @param list<float> $values must be non-empty
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = \count($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * Median Absolute Deviation — robust measure of statistical dispersion.
     *
     * @param list<float> $values
     */
    private function calculateMad(array $values, float $median): float
    {
        $absoluteDeviations = array_map(
            static fn (float $value): float => abs($value - $median),
            $values,
        );

        return $this->calculateMedian($absoluteDeviations);
    }

    /**
     * Builds month slot keys (YYYY-MM) for the trend lookback window ending before budget start.
     *
     * @return list<string>
     */
    private function buildTrendMonthSlots(CarbonImmutable $budgetStart): array
    {
        $slots = [];
        for ($index = self::TREND_LOOKBACK_MONTHS; $index >= 1; --$index) {
            $slots[] = $budgetStart->subMonths($index)->format('Y-m');
        }

        return $slots;
    }

    /**
     * Aggregates per-month-per-currency native amounts into base currency monthly totals.
     * Uses getActualsByCategoryByMonth format: [YYYY-MM][currency] = {income, expense}.
     * For insights we care about expense totals only.
     *
     * @param array<string, array<string, array{income: float, expense: float}>> $monthData
     *
     * @return array<string, float> YYYY-MM → expense total in base currency
     */
    /**
     * Rolls up per-category monthly data into a tree where each node's totals include all descendants.
     * Returns root-level entries (parent=null) with direct children, each having aggregated totals.
     *
     * @param array<int, array<string, array<string, array{income: float, expense: float}>>> $byMonth
     *
     * @return array<int, array{totals: array<string, float>, children: array<int, array<string, float>>}>
     */
    private function rollUpMonthlyTotalsByCategory(array $byMonth, string $baseCurrency): array
    {
        $descendantMap = $this->getDescendantMap();
        $parentMap = $this->getParentMap();

        $directTotalsByCategory = $this->computeDirectTotalsByCategory($byMonth, $baseCurrency);
        $aggregatedTotalsByCategory = $this->aggregateDescendantTotals($directTotalsByCategory, $descendantMap);

        $directChildrenByParent = [];
        foreach ($parentMap as $categoryId => $parentId) {
            if (null !== $parentId) {
                $directChildrenByParent[$parentId][] = $categoryId;
            }
        }

        $result = [];
        foreach ($aggregatedTotalsByCategory as $categoryId => $totals) {
            $parentId = $parentMap[$categoryId] ?? null;
            if (null !== $parentId) {
                continue;
            }

            $children = [];
            foreach ($directChildrenByParent[$categoryId] ?? [] as $childId) {
                if (isset($aggregatedTotalsByCategory[$childId])) {
                    $children[$childId] = $aggregatedTotalsByCategory[$childId];
                }
            }

            $result[$categoryId] = ['totals' => $totals, 'children' => $children];
        }

        return $result;
    }

    /**
     * Converts raw per-category per-month multi-currency data to base-currency expense totals.
     *
     * @param array<int, array<string, array<string, array{income: float, expense: float}>>> $byMonth
     *
     * @return array<int, array<string, float>> categoryId → [YYYY-MM → expense total in base currency]
     */
    private function computeDirectTotalsByCategory(array $byMonth, string $baseCurrency): array
    {
        $directTotals = [];
        foreach ($byMonth as $categoryId => $monthData) {
            $totals = $this->aggregateMonthlyTotalsInBaseCurrency($monthData, $baseCurrency);
            if ([] !== $totals) {
                $directTotals[$categoryId] = $totals;
            }
        }

        return $directTotals;
    }

    /**
     * For each category, sums direct totals from self and all descendants into one aggregated total.
     *
     * @param array<int, array<string, float>> $directTotalsByCategory categoryId → [YYYY-MM → expense total]
     * @param array<int, list<int>> $descendantMap categoryId → [self + all descendant IDs]
     *
     * @return array<int, array<string, float>> categoryId → [YYYY-MM → aggregated expense total]
     */
    private function aggregateDescendantTotals(array $directTotalsByCategory, array $descendantMap): array
    {
        $aggregated = [];

        foreach ($descendantMap as $categoryId => $descendantIds) {
            $monthlyTotals = [];
            foreach ($descendantIds as $descendantId) {
                foreach ($directTotalsByCategory[$descendantId] ?? [] as $month => $amount) {
                    $monthlyTotals[$month] = ($monthlyTotals[$month] ?? 0.0) + $amount;
                }
            }

            if ([] !== $monthlyTotals) {
                $aggregated[$categoryId] = $monthlyTotals;
            }
        }

        // Categories with direct totals not covered by the descendant map: use direct totals as-is
        foreach ($directTotalsByCategory as $categoryId => $totals) {
            if (!isset($aggregated[$categoryId])) {
                $aggregated[$categoryId] = $totals;
            }
        }

        return $aggregated;
    }

    /**
     * Computes trend for a single category given its monthly totals.
     *
     * @param array<string, float> $monthlyTotals
     * @param list<string> $monthSlots
     *
     * @return array{categoryId: int, direction: string, changePercent: float, recentAverage: float, olderAverage: float}|null
     */
    private function computeTrendForCategory(
        int $categoryId,
        array $monthlyTotals,
        array $monthSlots,
        int $halfPoint,
    ): ?array {
        if (\count($monthlyTotals) < self::TREND_MINIMUM_ACTIVE_MONTHS) {
            return null;
        }

        $olderSum = 0.0;
        $olderCount = 0;
        $recentSum = 0.0;
        $recentCount = 0;

        foreach ($monthSlots as $index => $month) {
            $total = $monthlyTotals[$month] ?? null;
            if (null === $total) {
                continue;
            }

            if ($index < $halfPoint) {
                $olderSum += $total;
                ++$olderCount;
            } else {
                $recentSum += $total;
                ++$recentCount;
            }
        }

        if (0 === $olderCount || $olderSum <= 0.0) {
            return null;
        }

        $olderAverage = $olderSum / $olderCount;
        $recentAverage = $recentCount > 0 ? $recentSum / $recentCount : 0.0;
        $changePercent = (($recentAverage - $olderAverage) / $olderAverage) * 100;

        if (abs($changePercent) < self::TREND_CHANGE_THRESHOLD_PERCENT) {
            return null;
        }

        return [
            'categoryId' => $categoryId,
            'direction' => $changePercent > 0 ? 'up' : 'down',
            'changePercent' => round($changePercent, 1),
            'recentAverage' => round($recentAverage, 2),
            'olderAverage' => round($olderAverage, 2),
        ];
    }

    /**
     * Computes seasonal pattern for a single category given its monthly totals.
     *
     * @param array<string, float> $monthlyTotals
     *
     * @return array{categoryId: int, seasonalFactor: float, currentMonthHistoricalAverage: float, overallMonthlyAverage: float, sampleYears: int}|null
     */
    private function computeSeasonalForCategory(
        int $categoryId,
        array $monthlyTotals,
        string $targetMonth,
    ): ?array {
        if (\count($monthlyTotals) < 2) {
            return null;
        }

        $overallSum = array_sum($monthlyTotals);
        $overallAverage = $overallSum / \count($monthlyTotals);

        if ($overallAverage <= 0.0) {
            return null;
        }

        $sameMonthValues = [];
        foreach ($monthlyTotals as $monthKey => $total) {
            if (substr($monthKey, 5, 2) === $targetMonth) {
                $sameMonthValues[] = $total;
            }
        }

        if ([] === $sameMonthValues) {
            return null;
        }

        $sameMonthAverage = array_sum($sameMonthValues) / \count($sameMonthValues);
        $seasonalFactor = $sameMonthAverage / $overallAverage;

        if (abs($seasonalFactor - 1.0) < self::SEASONAL_DEVIATION_THRESHOLD) {
            return null;
        }

        return [
            'categoryId' => $categoryId,
            'seasonalFactor' => round($seasonalFactor, 2),
            'currentMonthHistoricalAverage' => round($sameMonthAverage, 2),
            'overallMonthlyAverage' => round($overallAverage, 2),
            'sampleYears' => \count($sameMonthValues),
        ];
    }

    /**
     * Extracts the baseCurrency expense total per month from convertedValues data.
     * Since the data source (getConvertedMonthlyTotalsByCategory) provides amounts in all
     * currencies from the transaction snapshot, we only need the baseCurrency key.
     *
     * @param array<string, array<string, array{income: float, expense: float}>> $monthData
     *
     * @return array<string, float> YYYY-MM → expense total in base currency
     */
    private function aggregateMonthlyTotalsInBaseCurrency(array $monthData, string $baseCurrency): array
    {
        $totals = [];

        foreach ($monthData as $month => $currencyData) {
            $baseCurrencyValues = $currencyData[$baseCurrency] ?? null;
            if (null === $baseCurrencyValues) {
                continue;
            }

            $monthTotal = $baseCurrencyValues['expense'];
            if ($monthTotal > 0.0) {
                $totals[$month] = $monthTotal;
            }
        }

        return $totals;
    }
}
