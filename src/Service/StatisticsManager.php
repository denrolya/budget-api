<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\IncomeCategoryRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

final class StatisticsManager
{
    /**
     * Lazily populated once per request from buildDescendantMap().
     *
     * @var array<int, list<int>>|null
     */
    private ?array $descendantMap = null;

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
}
