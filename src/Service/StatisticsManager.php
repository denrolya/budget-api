<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\ExpenseRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;

final class StatisticsManager
{
    private TransactionRepository $transactionRepo;

    private ExpenseRepository $expenseRepo;

    private ExpenseCategoryRepository $expenseCategoryRepo;

    /** Lazily populated once per request from buildDescendantMap(). */
    private ?array $descendantMap = null;

    public function __construct(
        private AssetsManager $assetsManager,
        private EntityManagerInterface $em,
    ) {
        $this->expenseRepo = $this->em->getRepository(Expense::class);
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
        $this->expenseCategoryRepo = $this->em->getRepository(ExpenseCategory::class);
    }

    /**
     * Generates transactions statistics grouped by types. With given interval also grouped by date interval.
     * Single DB query for the full range; PHP partitioning per interval.
     * @return array<int, array{after: int, before: int, expense: float, income: float}>
     */
    public function calculateTransactionsValueByPeriod(
        CarbonPeriod $period,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
    ): array {
        $result = [];
        $dates = $period->toArray();
        $count = count($dates);

        $allTransactions = $this->transactionRepo->getList(
            after: $period->getStartDate(),
            before: $period->getEndDate(),
            type: $type,
            categories: $categories,
            accounts: $accounts,
        );

        foreach ($dates as $index => $after) {
            $isLast = $index === $count - 1;
            $before = $isLast ? $period->getEndDate() : $dates[$index + 1]->copy()->subSecond();
            // Exclusive upper bound that matches the repo's applyExecutedAtRange semantics
            $nextBound = $isLast
                ? $period->getEndDate()->copy()->addDay()->startOfDay()
                : $dates[$index + 1];

            $expenses = [];
            $incomes = [];

            foreach ($allTransactions as $transaction) {
                $executedAt = $transaction->getExecutedAt();
                if (!$executedAt->greaterThanOrEqualTo($after) || !$executedAt->lessThan($nextBound)) {
                    continue;
                }

                if ($transaction->getType() === Transaction::EXPENSE) {
                    $expenses[] = $transaction;
                } elseif ($transaction->getType() === Transaction::INCOME) {
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

    /**
     * TODO: Check efficiency. This is used in the category breakdown section, which is a key part of the app, so it needs to be optimized. The main potential bottleneck is the recursive getDescendantsFlat() calls for each category, which can lead to N+1 queries. To optimize, we can build a full descendant map in a single query and then use it to aggregate transactions without additional DB calls.
     * Builds category tree and sets value/total on each node.
     * Uses a pre-built descendant ID map instead of recursive getDescendantsFlat() / isChildOf() calls.
     * @return Category[] Root categories with nested children, each with value/total set.
     */
    public function generateCategoryTreeWithValues(
        array $transactions,
        string $type = null,
        array $categories = []
    ): array {
        $categories = $categories !== [] ? $categories : $this->getRootCategories($type);

        if ($categories === [] || $transactions === []) {
            return $categories;
        }

        // Index once; shared across all recursion levels.
        $transactionsByCatId = [];
        foreach ($transactions as $transaction) {
            $transactionsByCatId[$transaction->getCategory()->getId()][] = $transaction;
        }

        $this->hydrateCategoryValues($categories, $transactionsByCatId, $this->getDescendantMap());

        return $categories;
    }

    /**
     * @return array{min: array{value: float, when: string|null}, max: array{value: float, when: string|null}}
     */
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
                    static function (Transaction $transaction) use ($after, $before) {
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
     * Generates account value distribution statistics within given transactions array.
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
     * @return array<string, array<int, array{date: int, value: float}>> Category name → list of {date, value} for each interval.
     */
    public function generateCategoriesOnTimelineStatistics(
        CarbonPeriod $period,
        ?array $categories,
        array $transactions
    ): ?array {
        if ($categories === null || $categories === []) {
            return null;
        }

        $result = [];
        $start = $period->getStartDate();
        $end = $period->getEndDate();

        $descendantMap = $this->getDescendantMap();

        // Batch-fetch all requested categories in a single query (avoids N+1).
        $categoryEntities = $this->em->getRepository(Category::class)->findBy(['id' => $categories]);
        $categoriesById = [];
        foreach ($categoryEntities as $cat) {
            $categoriesById[$cat->getId()] = $cat;
        }

        // Index by category ID, pre-filtered to period bounds.
        $transactionsByCatId = [];
        foreach ($transactions as $transaction) {
            if ($transaction->getExecutedAt()->isBetween($start, $end)) {
                $transactionsByCatId[$transaction->getCategory()->getId()][] = $transaction;
            }
        }

        foreach ($categories as $categoryId) {
            $category = $categoriesById[(int) $categoryId] ?? null;
            if ($category === null) {
                continue;
            }

            $descendantIds = $descendantMap[(int) $categoryId] ?? [(int) $categoryId];

            $categoryTransactions = [];
            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $t) {
                    $categoryTransactions[] = $t;
                }
            }

            $result[$category->getName()] = $this->sumTransactionsByDateInterval($categoryTransactions, $period);
        }

        return $result;
    }

    /**
     * Find transaction category that holds the biggest cumulative value.
     * Used for: mainIncomeSource.
     */
    public function generateTopValueCategoryStatistics(array $transactions): ?array
    {
        if ($transactions === []) {
            return null;
        }

        $rootCategories = $this->em
            ->getRepository(Category::class)
            ->findBy([
                'root' => null,
                'isAffectingProfit' => true,
            ]);

        $descendantMap = $this->getDescendantMap();
        $max = 0;
        $result = null;

        $transactionsByCatId = [];
        foreach ($transactions as $transaction) {
            $transactionsByCatId[$transaction->getCategory()->getId()][] = $transaction;
        }

        foreach ($rootCategories as $category) {
            $descendantIds = $descendantMap[$category->getId()] ?? [$category->getId()];

            $subtreeTransactions = [];
            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $t) {
                    $subtreeTransactions[] = $t;
                }
            }

            $value = abs($this->assetsManager->sumTransactions($subtreeTransactions));

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

    public function generateTransactionsValueByCategoriesByWeekdays(array $transactionsOrdered): array
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $result = array_map(static fn($day) => ['name' => $day, 'values' => []], $days);

        $rootCategories = $this->em->getRepository(ExpenseCategory::class)->findRootCategories(['name' => 'ASC']);
        $descendantMap = $this->getDescendantMap();

        $transactionsByCatId = [];
        foreach ($transactionsOrdered as $transaction) {
            $transactionsByCatId[$transaction->getCategory()->getId()][] = $transaction;
        }

        foreach ($rootCategories as $category) {
            $categoryName = $category->getName();
            $descendantIds = $descendantMap[$category->getId()] ?? [$category->getId()];

            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $transaction) {
                    $weekday = $transaction->getExecutedAt()->dayOfWeekIso - 1;
                    $result[$weekday]['values'][$categoryName] =
                        ($result[$weekday]['values'][$categoryName] ?? 0) + $transaction->getValue();
                }
            }
        }

        foreach ($result as $index => &$weekdayData) {
            $weekdaysCount = $this->countWeekdaysBetweenDates(
                end($transactionsOrdered)->getExecutedAt(),
                $transactionsOrdered[0]->getExecutedAt(),
                $index
            );

            $weekdayData['values'] = array_map(static fn($value) => $value / $weekdaysCount, $weekdayData['values']);
        }

        return $result;
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
                static function (Transaction $transaction) use ($after, $before) {
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
                static function (Transaction $transaction) use ($after, $before) {
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

    private function getRootCategories(?string $type): array
    {
        $categoryClass = match ($type) {
            Category::EXPENSE_CATEGORY_TYPE => ExpenseCategory::class,
            Category::INCOME_CATEGORY_TYPE => IncomeCategory::class,
            default => Category::class,
        };

        return $this->em->getRepository($categoryClass)->findRootCategories();
    }

    /**
     * Lazily builds and caches a map of categoryId → [self + all descendant ids] for the current request.
     * Single DB query; eliminates all getDescendantsFlat() / isChildOf() recursive loads.
     */
    private function getDescendantMap(): array
    {
        if ($this->descendantMap === null) {
            $this->descendantMap = $this->em->getRepository(Category::class)->buildDescendantMap();
        }

        return $this->descendantMap;
    }

    /**
     * Recursively hydrates setValue/setTotal on each category using a pre-built descendant ID map.
     * Eliminates isChildOf() — no per-transaction recursive DB queries.
     */
    private function hydrateCategoryValues(
        array $categories,
        array $transactionsByCatId,
        array $descendantMap
    ): void {
        foreach ($categories as $category) {
            $catId = $category->getId();
            $descendantIds = $descendantMap[$catId] ?? [$catId];

            // Direct value: only transactions assigned to this exact category.
            $category->setValue(
                $this->assetsManager->sumTransactions($transactionsByCatId[$catId] ?? [])
            );

            // Total value: all transactions in the subtree (self + descendants).
            $subtree = [];
            foreach ($descendantIds as $descId) {
                foreach ($transactionsByCatId[$descId] ?? [] as $t) {
                    $subtree[] = $t;
                }
            }
            $category->setTotal($this->assetsManager->sumTransactions($subtree));

            // Recurse into children — one EXTRA_LAZY SELECT per non-leaf, not per-transaction.
            if (count($descendantIds) > 1 && $category->hasChildren()) {
                $this->hydrateCategoryValues(
                    $category->getChildren()->toArray(),
                    $transactionsByCatId,
                    $descendantMap
                );
            }
        }
    }

    private function countWeekdaysBetweenDates(CarbonInterface $after, CarbonInterface $before, int $weekday): int
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
