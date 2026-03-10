<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;

final class StatisticsManager
{
    private TransactionRepository $transactionRepo;

    /** Lazily populated once per request from buildDescendantMap(). */
    private ?array $descendantMap = null;

    public function __construct(
        private AssetsManager $assetsManager,
        private EntityManagerInterface $em,
    ) {
        /** @var TransactionRepository $transactionRepo */
        $transactionRepo = $this->em->getRepository(Transaction::class);
        $this->transactionRepo = $transactionRepo;
    }

    /**
     * Generates transactions statistics grouped by types. With given interval also grouped by date interval.
        * Single DB query for the full range; PHP interval bucketing in one pass.
     * @return array<int, array{after: int, before: int, expense: float, income: float}>
     */
    public function calculateTransactionsValueByPeriod(
        CarbonPeriod $period,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
    ): array {
        $allTransactions = $this->transactionRepo->getList(
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
                if ($transaction->getType() === Transaction::EXPENSE) {
                    $expenses[] = $transaction;
                } elseif ($transaction->getType() === Transaction::INCOME) {
                    $incomes[] = $transaction;
                }
            }

            $result[] = [
                'after' => $bound['after']->timestamp,
                'before' => $bound['before']->timestamp,
                'expense' => $this->assetsManager->sumTransactions($expenses),
                'income' => $this->assetsManager->sumTransactions($incomes),
            ];
        }

        return $result;
    }

    /**
     * Builds category tree and sets value/total on each node.
     * Uses a pre-built descendant ID map instead of recursive getDescendantsFlat() / isChildOf() calls.
     * @return Category[] Root categories with nested children, each with value/total set.
     */
    public function generateCategoryTreeWithValues(
        array $transactions,
        ?string $type = null,
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
            $result[$category->getName()] = $this->sumTransactionsByDateInterval(
                $this->collectTransactionsForDescendants($descendantIds, $transactionsByCatId),
                $period
            );
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
        $bounds = $this->buildPeriodBounds($period, false);
        $bucketed = $this->bucketTransactionsByPeriod($transactions, $bounds);

        $result = [];
        foreach ($bounds as $index => $bound) {
            $result[] = [
                'date' => $bound['after']->timestamp,
                'value' => $this->assetsManager->sumTransactions($bucketed[$index]),
            ];
        }

        return $result;
    }

    public function averageByPeriod(array $transactions, CarbonPeriod $period): array
    {
        $bounds = $this->buildPeriodBounds($period, false);
        $bucketed = $this->bucketTransactionsByPeriod($transactions, $bounds);

        $result = [];
        foreach ($bounds as $index => $bound) {
            $transactionsWithinPeriod = $bucketed[$index];
            $result[] = [
                'date' => $bound['after']->timestamp,
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

        $startDate = $after->copy()->startOfDay();
        $endDate = $before->copy()->startOfDay();

        if ($startDate->greaterThan($endDate)) {
            return 0;
        }

        $totalDays = $startDate->diffInDays($endDate) + 1;
        $fullWeeks = intdiv($totalDays, 7);
        $count = $fullWeeks;

        $remainder = $totalDays % 7;
        $startWeekday = $startDate->dayOfWeek;

        for ($i = 0; $i < $remainder; $i++) {
            if ((($startWeekday + $i) % 7) === $weekday) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{after: CarbonInterface, before: CarbonInterface, nextBound: CarbonInterface}>
     */
    private function buildPeriodBounds(CarbonPeriod $period, bool $includeLastDay = true): array
    {
        $dates = $period->toArray();
        $count = count($dates);
        $bounds = [];

        foreach ($dates as $index => $after) {
            $isLast = $index === $count - 1;
            $before = $isLast ? $period->getEndDate() : $dates[$index + 1]->copy()->subSecond();
            $nextBound = $isLast
                ? ($includeLastDay ? $period->getEndDate()->copy()->addDay()->startOfDay() : $period->getEndDate())
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
     * @param array<int, array{after: CarbonInterface, before: CarbonInterface, nextBound: CarbonInterface}> $bounds
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
