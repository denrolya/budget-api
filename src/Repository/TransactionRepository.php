<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\CountWalker;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;

/**
 * QueryBuilder-backed repository with centralized filter composition.
 *
 * @method Transaction|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transaction|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transaction[] findAll()
 * @method Transaction[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TransactionRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 30;
    public const ORDER_FIELD = 'executedAt';
    public const ORDER = 'DESC';

    private const ALLOWED_ORDER_FIELDS = ['executedAt', 'id', 'amount', 'createdAt'];
    private const ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

    public function __construct(ManagerRegistry $registry, ?string $classname = null)
    {
        parent::__construct($registry, $classname ?? Transaction::class);
    }

    /**
     * @return array<Transaction>
     */
    public function getList(
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = [],
        bool $affectingProfitOnly = true,
        ?bool $isDraft = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        array $debts = [],
        ?array $currencies = null,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
    ): array {
        return $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            affectingProfitOnly: $affectingProfitOnly,
            type: $type,
            categories: $categories,
            accounts: $accounts,
            excludedCategories: $excludedCategories,
            isDraft: $isDraft,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
            debts: $debts,
            currencies: $currencies,
            orderField: $orderField,
            order: $order,
        )
            ->getQuery()
            ->getResult();
    }

    /**
     * @return DoctrinePaginator<Transaction>
     */
    public function getPaginator(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        bool $affectingProfitOnly = false,
        ?string $type = null,
        ?array $categories = null,
        ?array $accounts = null,
        ?array $excludedCategories = null,
        ?bool $isDraft = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $debts = null,
        ?array $currencies = null,
        int $limit = self::PER_PAGE,
        int $page = 1,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
        bool $excludeTransferTransactions = false,
    ): DoctrinePaginator {
        $queryBuilder = $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            affectingProfitOnly: $affectingProfitOnly,
            type: $type,
            categories: $categories,
            accounts: $accounts,
            excludedCategories: $excludedCategories,
            isDraft: $isDraft,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
            debts: $debts,
            currencies: $currencies,
            orderField: $orderField,
            order: $order,
            excludeTransferTransactions: $excludeTransferTransactions,
        );

        $firstResult = max(0, $page - 1) * $limit;
        $query = $queryBuilder
            ->setFirstResult($firstResult)
            ->setMaxResults($limit)
            ->getQuery();

        if (0 === \count($queryBuilder->getDQLPart('join'))) {
            $query->setHint(CountWalker::HINT_DISTINCT, false);
        }

        $paginator = new DoctrinePaginator($query, true);

        $hasHaving = \count($queryBuilder->getDQLPart('having') ?? []) > 0;
        $paginator->setUseOutputWalkers($hasHaving);

        return $paginator;
    }

    /**
     * Returns all transactions matching the given filters, excluding transfer-linked transactions.
     * Designed for the unified ledger endpoint — fetches up to $limit items for PHP-level merge.
     * Use countForLedger() to get the true total without this limit.
     *
     * @return array<Transaction>
     */
    public function getListForLedger(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        ?string $type = null,
        ?array $accounts = null,
        ?array $categories = null,
        ?array $debts = null,
        ?string $note = null,
        ?bool $isDraft = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $currencies = null,
        int $limit = 10000,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
    ): array {
        return $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            affectingProfitOnly: false,
            type: $type,
            categories: $categories,
            accounts: $accounts,
            debts: $debts,
            isDraft: $isDraft,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
            currencies: $currencies,
            orderField: $orderField,
            order: $order,
            excludeTransferTransactions: true,
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the exact count of transactions matching the ledger filters.
     * Separate from getListForLedger to keep the list fetch independent of the total count.
     */
    public function countForLedger(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        ?string $type = null,
        ?array $accounts = null,
        ?array $categories = null,
        ?array $debts = null,
        ?string $note = null,
        ?bool $isDraft = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $currencies = null,
    ): int {
        return (int) $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            affectingProfitOnly: false,
            type: $type,
            categories: $categories,
            accounts: $accounts,
            debts: $debts,
            isDraft: $isDraft,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
            currencies: $currencies,
            excludeTransferTransactions: true,
        )
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns the net converted value of matching transactions in the given base currency.
     * Incomes are positive, expenses are negative. Uses SQL SUM — no object hydration.
     */
    public function sumConverted(
        string $baseCurrency,
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        bool $affectingProfitOnly = false,
        ?string $type = null,
        ?array $categories = null,
        ?array $accounts = null,
        ?array $excludedCategories = null,
        ?bool $isDraft = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $debts = null,
        ?array $currencies = null,
        bool $excludeTransferTransactions = false,
    ): float {
        $jsonPath = '$.' . $baseCurrency;

        $sum = function (string $forType) use (
            $jsonPath, $after, $before, $affectingProfitOnly,
            $categories, $accounts, $excludedCategories,
            $isDraft, $note, $amountGte, $amountLte, $debts, $currencies,
            $excludeTransferTransactions
        ): float {
            return (float) ($this->getBaseQueryBuilder(
                after: $after,
                before: $before,
                affectingProfitOnly: $affectingProfitOnly,
                type: $forType,
                categories: $categories,
                accounts: $accounts,
                excludedCategories: $excludedCategories,
                isDraft: $isDraft,
                note: $note,
                amountGte: $amountGte,
                amountLte: $amountLte,
                debts: $debts,
                currencies: $currencies,
                excludeTransferTransactions: $excludeTransferTransactions,
            )
                ->select('SUM(JSON_EXTRACT(t.convertedValues, :jsonPath))')
                ->setParameter('jsonPath', $jsonPath)
                ->getQuery()
                ->getSingleScalarResult() ?? 0);
        };

        if (Transaction::INCOME === $type) {
            return $sum(Transaction::INCOME);
        }

        if (Transaction::EXPENSE === $type) {
            return -$sum(Transaction::EXPENSE);
        }

        // Mixed: net = income − expense
        return $sum(Transaction::INCOME) - $sum(Transaction::EXPENSE);
    }

    /**
     * @param int[] $accountIdentifiers
     *
     * @return array<int, int> Map of accountId → draft count
     */
    public function countDraftsByAccountIdentifiers(array $accountIdentifiers): array
    {
        if ([] === $accountIdentifiers) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('transaction')
            ->select('IDENTITY(transaction.account) AS accountId')
            ->addSelect('COUNT(transaction.id) AS draftCount')
            ->where('transaction.isDraft = :isDraft')
            ->andWhere('transaction.account IN (:accountIdentifiers)')
            ->setParameter('isDraft', true)
            ->setParameter('accountIdentifiers', $accountIdentifiers)
            ->groupBy('transaction.account');

        /** @var array<array{accountId: int, draftCount: string}> $rows */
        $rows = $queryBuilder->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['accountId']] = (int) $row['draftCount'];
        }

        return $counts;
    }

    /**
     * Single source of truth for listing filters.
     */
    protected function getBaseQueryBuilder(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        bool $affectingProfitOnly = false,
        ?string $type = null,
        ?array $categories = null,
        ?array $accounts = null,
        ?array $excludedCategories = null,
        ?bool $isDraft = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $debts = null,
        ?array $currencies = null,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
        bool $excludeTransferTransactions = false,
    ): QueryBuilder {
        $orderField = $this->assertOrderField($orderField);
        $order = $this->assertOrderDirection($order);

        if (!\is_string($type) || '' === $type) {
            $type = null;
        }

        $queryBuilder = $this->createQueryBuilder('t')
            ->orderBy("t.$orderField", $order)
            ->addOrderBy('t.id', $order);

        $this->applyDraftFilter($queryBuilder, $isDraft);
        $this->applyExecutedAtRange($queryBuilder, $after, $before);
        $this->applyTypeFilter($queryBuilder, $type);
        $this->applyInFilters($queryBuilder, $accounts, $categories, $excludedCategories, $debts);
        $this->applyAffectingProfitFilter($queryBuilder, $affectingProfitOnly);
        $this->applyNoteFilter($queryBuilder, $note);
        $this->applyAmountRangeFilter($queryBuilder, $amountGte, $amountLte);
        $this->applyCurrencyFilter($queryBuilder, $currencies);

        if ($excludeTransferTransactions) {
            $queryBuilder->andWhere('t.transfer IS NULL');
        }

        return $queryBuilder;
    }

    private function assertOrderField(string $orderField): string
    {
        if (!\in_array($orderField, self::ALLOWED_ORDER_FIELDS, true)) {
            throw new InvalidArgumentException('Invalid order field');
        }

        return $orderField;
    }

    private function assertOrderDirection(string $order): string
    {
        $order = strtoupper($order);

        if (!\in_array($order, self::ALLOWED_ORDER_DIRECTIONS, true)) {
            throw new InvalidArgumentException('Invalid order direction');
        }

        return $order;
    }

    private function applyDraftFilter(QueryBuilder $queryBuilder, ?bool $isDraft): void
    {
        if (null === $isDraft) {
            return;
        }

        $queryBuilder->andWhere('t.isDraft = :isDraft')
            ->setParameter('isDraft', $isDraft);
    }

    private function applyTypeFilter(QueryBuilder $queryBuilder, ?string $type): void
    {
        if (null === $type) {
            return;
        }

        if (!\in_array($type, [Transaction::EXPENSE, Transaction::INCOME], true)) {
            throw new InvalidArgumentException('Invalid transaction type');
        }

        if (Transaction::EXPENSE === $type) {
            $queryBuilder->andWhere($queryBuilder->expr()->isInstanceOf('t', Expense::class));

            return;
        }

        $queryBuilder->andWhere($queryBuilder->expr()->isInstanceOf('t', Income::class));
    }

    private function applyInFilters(
        QueryBuilder $queryBuilder,
        ?array $accounts,
        ?array $categories,
        ?array $excludedCategories,
        ?array $debts,
    ): void {
        if ($accounts) {
            $queryBuilder->andWhere('t.account IN (:accounts)')
                ->setParameter('accounts', $accounts);
        }

        if ($categories) {
            $queryBuilder->andWhere('t.category IN (:categories)')
                ->setParameter('categories', $categories);
        }

        if ($excludedCategories) {
            $queryBuilder->andWhere('t.category NOT IN (:excludedCategories)')
                ->setParameter('excludedCategories', $excludedCategories);
        }

        if ($debts) {
            $queryBuilder->andWhere('t.debt IN (:debts)')
                ->setParameter('debts', $debts);
        }
    }

    private function applyAffectingProfitFilter(QueryBuilder $queryBuilder, bool $affectingProfitOnly): void
    {
        if (!$affectingProfitOnly) {
            return;
        }

        // join only when needed
        $queryBuilder->innerJoin('t.category', 'c')
            ->andWhere('c.isAffectingProfit = :affectingProfitOnly')
            ->setParameter('affectingProfitOnly', true);
    }

    private function applyNoteFilter(QueryBuilder $queryBuilder, ?string $note): void
    {
        if (!\is_string($note)) {
            return;
        }

        $needle = trim($note);
        if ('' === $needle) {
            return;
        }

        // escape LIKE special chars: \ % _
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle);

        $queryBuilder->andWhere('t.note LIKE :note')
            ->setParameter('note', '%' . $needle . '%');
    }

    private function applyAmountRangeFilter(QueryBuilder $queryBuilder, ?float $amountGte, ?float $amountLte): void
    {
        if (null !== $amountGte) {
            $queryBuilder->andWhere('t.amount >= :amountGte')
                ->setParameter('amountGte', $amountGte);
        }

        if (null !== $amountLte) {
            $queryBuilder->andWhere('t.amount <= :amountLte')
                ->setParameter('amountLte', $amountLte);
        }
    }

    private function applyExecutedAtRange(
        QueryBuilder $queryBuilder,
        ?CarbonInterface $after,
        ?CarbonInterface $before,
    ): void {
        if (null !== $after) {
            $queryBuilder->andWhere('t.executedAt >= :afterStart')
                ->setParameter('afterStart', $after->copy()->startOfDay());
        }

        if (null !== $before) {
            $queryBuilder->andWhere('t.executedAt < :beforeEndExclusive')
                ->setParameter('beforeEndExclusive', $before->copy()->addDay()->startOfDay());
        }
    }

    private function applyCurrencyFilter(QueryBuilder $queryBuilder, ?array $currencies): void
    {
        if (!$currencies) {
            return;
        }

        $flat = [];
        array_walk_recursive($currencies, static function (mixed $v) use (&$flat): void { $flat[] = $v; });

        $normalized = [];
        foreach ($flat as $currencyValue) {
            if (!\is_string($currencyValue)) {
                continue;
            }
            $code = strtoupper(trim($currencyValue));
            if ('' === $code) {
                continue;
            }
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                throw new InvalidArgumentException('Invalid currency');
            }
            $normalized[] = $code;
        }

        $normalized = array_values(array_unique($normalized));
        if ([] === $normalized) {
            return;
        }

        $queryBuilder->innerJoin('t.account', 'a')
            ->andWhere('a.currency IN (:currencies)')
            ->setParameter('currencies', $normalized);
    }

    /**
     * Returns transaction counts and volumes grouped by day, applying the full filter set.
     * Uses DQL (no raw SQL), so all filters are handled through getBaseQueryBuilder.
     *
     * @return array<array{day: string, count: int, convertedValues: array<string, array{income: float, expense: float}>}>
     */
    public function countByDay(
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        bool $affectingProfitOnly = false,
        ?string $type = null,
        ?array $categories = null,
        ?array $accounts = null,
        ?array $excludedCategories = null,
        ?bool $isDraft = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $debts = null,
        ?array $currencies = null,
    ): array {
        // Runs the base query for a specific transaction type (income or expense),
        // grouped by day + native account currency. Returns scalar rows.
        $buildGrouped = function (string $forType) use (
            $after, $before, $affectingProfitOnly, $type,
            $categories, $accounts, $excludedCategories,
            $isDraft, $note, $amountGte, $amountLte, $debts, $currencies
        ): array {
            // When a conflicting type filter is already active, nothing will match.
            if (null !== $type && $type !== $forType) {
                return [];
            }

            $queryBuilder = $this->getBaseQueryBuilder(
                after: $after,
                before: $before,
                affectingProfitOnly: $affectingProfitOnly,
                type: $forType,
                categories: $categories,
                accounts: $accounts,
                excludedCategories: $excludedCategories,
                isDraft: $isDraft,
                note: $note,
                amountGte: $amountGte,
                amountLte: $amountLte,
                debts: $debts,
                currencies: $currencies,
            );

            // Join account for native currency grouping. Doctrine allows a second join on
            // the same association with a different alias (e.g. applyCurrencyFilter uses 'a').
            return $queryBuilder
                ->leftJoin('t.account', '_acnt')
                ->select('DATE(t.executedAt) AS day, _acnt.currency AS currency, COUNT(t.id) AS cnt, SUM(t.amount) AS total')
                ->resetDQLPart('orderBy')
                ->groupBy('day, _acnt.currency')
                ->orderBy('day', 'ASC')
                ->getQuery()
                ->getArrayResult();
        };

        $incomeRows = $buildGrouped(Transaction::INCOME);
        $expenseRows = $buildGrouped(Transaction::EXPENSE);

        $pivoted = [];

        /** @var array{day: string, currency: string, cnt: int|string, total: float|string|null} $row */
        foreach ($incomeRows as $row) {
            $day = (string) $row['day'];
            $currency = (string) $row['currency'];
            $pivoted[$day] ??= ['day' => $day, 'count' => 0, 'convertedValues' => []];
            $pivoted[$day]['count'] += (int) $row['cnt'];
            $pivoted[$day]['convertedValues'][$currency] ??= ['income' => 0.0, 'expense' => 0.0];
            $pivoted[$day]['convertedValues'][$currency]['income'] = (float) $row['total'];
        }

        /** @var array{day: string, currency: string, cnt: int|string, total: float|string|null} $row */
        foreach ($expenseRows as $row) {
            $day = (string) $row['day'];
            $currency = (string) $row['currency'];
            $pivoted[$day] ??= ['day' => $day, 'count' => 0, 'convertedValues' => []];
            $pivoted[$day]['count'] += (int) $row['cnt'];
            $pivoted[$day]['convertedValues'][$currency] ??= ['income' => 0.0, 'expense' => 0.0];
            $pivoted[$day]['convertedValues'][$currency]['expense'] = (float) $row['total'];
        }

        ksort($pivoted);

        return array_values($pivoted);
    }

    /**
     * Returns the number of distinct calendar months (YYYY-MM) that had at least one
     * transaction per category, for the given date range.
     *
     * @return array<int, int> categoryId → active month count
     */
    public function getCategoryActiveMonths(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $rows = $this->createQueryBuilder('t')
            ->innerJoin('t.category', 'c')
            ->select(
                'IDENTITY(t.category) AS category_id',
                'DATE(t.executedAt) AS day',
            )
            ->andWhere('t.executedAt >= :start')
            ->andWhere('t.executedAt <= :end')
            ->andWhere('c.isAffectingProfit = :isAffectingProfit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('isAffectingProfit', true)
            ->groupBy('category_id, day')
            ->getQuery()
            ->getArrayResult();

        $monthsByCat = [];
        /** @var array{category_id: int|string, day: string} $row */
        foreach ($rows as $row) {
            $catId = (int) $row['category_id'];
            $month = substr($row['day'], 0, 7); // 'YYYY-MM'
            $monthsByCat[$catId][$month] = true;
        }

        $result = [];
        foreach ($monthsByCat as $catId => $months) {
            $result[$catId] = \count($months);
        }

        return $result;
    }

    /**
     * Returns actual income/expense amounts per category for a given date range,
     * aggregated per currency using each transaction's stored convertedValues snapshot.
     * Using historical snapshots keeps results consistent with value-by-period totals.
     * Only includes categories where isAffectingProfit = true.
     *
     * @return array<array{categoryId: int, convertedValues: array<string, array{income: float, expense: float}>}>
     */
    public function getActualsByCategoryForPeriod(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $rows = $this->createQueryBuilder('t')
            ->innerJoin('t.category', 'c')
            ->select(
                'IDENTITY(t.category) AS category_id',
                't.convertedValues AS converted_values',
                'CASE WHEN t INSTANCE OF ' . Income::class . " THEN 'income' ELSE 'expense' END AS transaction_type",
            )
            ->andWhere('t.executedAt >= :start')
            ->andWhere('t.executedAt <= :end')
            ->andWhere('c.isAffectingProfit = :isAffectingProfit')
            // Compensation incomes are isAffectingProfit=false and therefore already excluded
            // by the filter above. They are NOT subtracted from expense here — budget analytics
            // shows gross expense per category; net calculation is handled by the statistics layer.
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('isAffectingProfit', true)
            ->getQuery()
            ->getArrayResult();

        $pivoted = [];
        /** @var array{category_id: int|string, transaction_type: string, converted_values: array<string, float|int|string>|null} $row */
        foreach ($rows as $row) {
            $catId = (int) $row['category_id'];
            $type = $row['transaction_type'];
            $pivoted[$catId] ??= ['categoryId' => $catId, 'convertedValues' => []];
            $convertedValues = $row['converted_values'] ?? [];
            foreach ($convertedValues as $currencyKey => $value) {
                $pivoted[$catId]['convertedValues'][$currencyKey] ??= ['income' => 0.0, 'expense' => 0.0];
                $pivoted[$catId]['convertedValues'][$currencyKey][$type] += (float) $value;
            }
        }

        return array_values($pivoted);
    }

    /**
     * Returns income/expense amounts per category grouped by calendar month (YYYY-MM) and
     * the account's native currency. Aggregates day-level rows into monthly totals in PHP,
     * using the same DATE() trick as getCategoryActiveMonths.
     *
     * @return array<int, array<string, array<string, array{income: float, expense: float}>>>
     *                                                                                        [catId][YYYY-MM][currency] → {income, expense}
     */
    public function getActualsByCategoryByMonth(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $rows = $this->createQueryBuilder('t')
            ->innerJoin('t.account', 'a')
            ->innerJoin('t.category', 'c')
            ->select(
                'IDENTITY(t.category) AS category_id',
                'DATE(t.executedAt) AS day',
                'a.currency AS currency',
                'SUM(CASE WHEN t INSTANCE OF ' . Income::class . ' THEN t.amount ELSE 0 END) AS income',
                'SUM(CASE WHEN t INSTANCE OF ' . Expense::class . ' THEN t.amount ELSE 0 END) AS expense',
            )
            ->andWhere('t.executedAt >= :start')
            ->andWhere('t.executedAt <= :end')
            ->andWhere('c.isAffectingProfit = :isAffectingProfit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('isAffectingProfit', true)
            ->groupBy('category_id, day, a.currency')
            ->orderBy('category_id', 'ASC')
            ->addOrderBy('day', 'ASC')
            ->addOrderBy('a.currency', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Aggregate day-level rows into [catId][YYYY-MM][currency] monthly totals
        $result = [];
        /** @var array{category_id: int|string, day: string, currency: string, income: float|string, expense: float|string} $row */
        foreach ($rows as $row) {
            $catId = (int) $row['category_id'];
            $month = substr($row['day'], 0, 7); // 'YYYY-MM'
            $currency = $row['currency'];

            $result[$catId][$month][$currency] ??= ['income' => 0.0, 'expense' => 0.0];
            $result[$catId][$month][$currency]['income'] += (float) $row['income'];
            $result[$catId][$month][$currency]['expense'] += (float) $row['expense'];
        }

        return $result;
    }

    /**
     * Returns income/expense amounts per category grouped by calendar month (YYYY-MM),
     * using each transaction's stored convertedValues snapshot for accurate multi-currency totals.
     *
     * Unlike getActualsByCategoryByMonth (which uses native account currency amounts),
     * this method reads the JSON convertedValues field so amounts are already converted
     * to all supported currencies at the time of transaction creation.
     *
     * @return array<int, array<string, array<string, array{income: float, expense: float}>>>
     *                                                                                        [catId][YYYY-MM][currency] → {income, expense}
     */
    public function getConvertedMonthlyTotalsByCategory(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $rows = $this->createQueryBuilder('t')
            ->innerJoin('t.category', 'c')
            ->select(
                'IDENTITY(t.category) AS category_id',
                'DATE(t.executedAt) AS day',
                't.convertedValues AS converted_values',
                'CASE WHEN t INSTANCE OF ' . Income::class . " THEN 'income' ELSE 'expense' END AS transaction_type",
            )
            ->andWhere('t.executedAt >= :start')
            ->andWhere('t.executedAt <= :end')
            ->andWhere('c.isAffectingProfit = :isAffectingProfit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('isAffectingProfit', true)
            ->getQuery()
            ->getArrayResult();

        /** @var array<int, array<string, array<string, array{income: float, expense: float}>>> $result */
        $result = [];
        /** @var array{category_id: int|string, day: string, transaction_type: string, converted_values: array<string, float|int|string>|null} $row */
        foreach ($rows as $row) {
            $categoryId = (int) $row['category_id'];
            $month = substr($row['day'], 0, 7);
            $type = $row['transaction_type'];
            $convertedValues = $row['converted_values'] ?? [];

            foreach ($convertedValues as $currency => $value) {
                if (!isset($result[$categoryId][$month][$currency])) {
                    $result[$categoryId][$month][$currency] = ['income' => 0.0, 'expense' => 0.0];
                }
                if ('income' === $type) {
                    $result[$categoryId][$month][$currency]['income'] += (float) $value;
                } else {
                    $result[$categoryId][$month][$currency]['expense'] += (float) $value;
                }
            }
        }

        return $result;
    }

    /**
     * Returns per-day income/expense amounts per category for a given date range.
     * Same pivot pattern as getActualsByCategoryForPeriod but also groups by day.
     *
     * @return array<array{categoryId: int, days: array<array{day: string, convertedValues: array<string, array{income: float, expense: float}>}>}>
     */
    public function getCategoryDailyStatsForPeriod(DateTimeInterface $start, DateTimeInterface $end): array
    {
        $rows = $this->createQueryBuilder('t')
            ->innerJoin('t.category', 'c')
            ->select(
                'IDENTITY(t.category) AS category_id',
                'DATE(t.executedAt) AS day',
                't.convertedValues AS converted_values',
                'CASE WHEN t INSTANCE OF ' . Income::class . " THEN 'income' ELSE 'expense' END AS transaction_type",
            )
            ->andWhere('t.executedAt >= :start')
            ->andWhere('t.executedAt <= :end')
            ->andWhere('c.isAffectingProfit = :isAffectingProfit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('isAffectingProfit', true)
            ->orderBy('category_id', 'ASC')
            ->addOrderBy('day', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byCat = [];
        /** @var array{category_id: int|string, day: string, transaction_type: string, converted_values: array<string, float|int|string>|null} $row */
        foreach ($rows as $row) {
            $catId = (int) $row['category_id'];
            $day = $row['day'];
            $type = $row['transaction_type'];
            $byCat[$catId] ??= [];
            $byCat[$catId][$day] ??= ['day' => $day, 'convertedValues' => []];
            $convertedValues = $row['converted_values'] ?? [];
            foreach ($convertedValues as $currencyKey => $value) {
                $byCat[$catId][$day]['convertedValues'][$currencyKey] ??= ['income' => 0.0, 'expense' => 0.0];
                $byCat[$catId][$day]['convertedValues'][$currencyKey][$type] += (float) $value;
            }
        }

        $result = [];
        foreach ($byCat as $catId => $days) {
            $result[] = ['categoryId' => $catId, 'days' => array_values($days)];
        }

        return $result;
    }
}
