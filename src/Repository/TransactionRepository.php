<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountLogEntry;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use App\Pagination\Paginator;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;

/**
 * @method Transaction|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transaction|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transaction[]    findAll()
 * @method Transaction[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TransactionRepository extends ServiceEntityRepository
{
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
        string $order = self::ORDER
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
            order: $order
        )
            ->getQuery()
            ->getResult();
    }

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
        int $limit = Paginator::PER_PAGE,
        int $page = 1,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER
    ): Paginator {
        $qb = $this->getBaseQueryBuilder(
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
            order: $order
        );

        return (new Paginator($qb, $limit))->paginate($page);
    }

    /**
     * Compatibility wrapper: find by executedAt range.
     *
     * @return array<Transaction>
     */
    public function findWithinPeriod(CarbonInterface $after, ?CarbonInterface $before = null): array
    {
        return $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            affectingProfitOnly: false,
            orderField: self::ORDER_FIELD,
            order: self::ORDER
        )
            ->getQuery()
            ->getResult();
    }

    /**
     * NOTE: name preserved to avoid breaking callers, but semantics are "after last log".
     *
     * @return array<Transaction>
     */
    public function findBeforeLastLog(Account $account): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy('t.executedAt', 'DESC');

        $lastLogEntry = $this->getEntityManager()
            ->getRepository(AccountLogEntry::class)
            ->findOneBy(['account' => $account], ['createdAt' => 'DESC']);

        if ($lastLogEntry) {
            $qb->andWhere('t.executedAt > :date')
                ->setParameter('date', $lastLogEntry->getCreatedAt());
        }

        return $qb->getQuery()->getResult();
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
    ): float {
        $jsonPath = '$.' . $baseCurrency;

        $sum = function (string $forType) use (
            $jsonPath, $after, $before, $affectingProfitOnly,
            $categories, $accounts, $excludedCategories,
            $isDraft, $note, $amountGte, $amountLte, $debts, $currencies
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
            )
                ->select('SUM(JSON_EXTRACT(t.convertedValues, :jsonPath))')
                ->setParameter('jsonPath', $jsonPath)
                ->getQuery()
                ->getSingleScalarResult() ?? 0);
        };

        if ($type === Transaction::INCOME) {
            return $sum(Transaction::INCOME);
        }

        if ($type === Transaction::EXPENSE) {
            return -$sum(Transaction::EXPENSE);
        }

        // Mixed: net = income − expense
        return $sum(Transaction::INCOME) - $sum(Transaction::EXPENSE);
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
        string $order = self::ORDER
    ): QueryBuilder {
        $orderField = $this->assertOrderField($orderField);
        $order = $this->assertOrderDirection($order);

        if (!is_string($type) || $type === '') {
            $type = null;
        }

        $qb = $this->createQueryBuilder('t')
            ->orderBy("t.$orderField", $order)
            ->addOrderBy('t.id', $order);

        $this->applyDraftFilter($qb, $isDraft);
        $this->applyExecutedAtRange($qb, $after, $before);
        $this->applyTypeFilter($qb, $type);
        $this->applyInFilters($qb, $accounts, $categories, $excludedCategories, $debts);
        $this->applyAffectingProfitFilter($qb, $affectingProfitOnly);
        $this->applyNoteFilter($qb, $note);
        $this->applyAmountRangeFilter($qb, $amountGte, $amountLte);
        $this->applyCurrencyFilter($qb, $currencies);

        return $qb;
    }

    private function assertOrderField(string $orderField): string
    {
        if (!in_array($orderField, self::ALLOWED_ORDER_FIELDS, true)) {
            throw new InvalidArgumentException('Invalid order field');
        }

        return $orderField;
    }

    private function assertOrderDirection(string $order): string
    {
        $order = strtoupper($order);

        if (!in_array($order, self::ALLOWED_ORDER_DIRECTIONS, true)) {
            throw new InvalidArgumentException('Invalid order direction');
        }

        return $order;
    }

    private function applyDraftFilter(QueryBuilder $qb, ?bool $isDraft): void
    {
        if ($isDraft === null) {
            return;
        }

        $qb->andWhere('t.isDraft = :isDraft')
            ->setParameter('isDraft', $isDraft);
    }

    private function applyTypeFilter(QueryBuilder $qb, ?string $type): void
    {
        if ($type === null) {
            return;
        }

        if (!in_array($type, [Transaction::EXPENSE, Transaction::INCOME], true)) {
            throw new InvalidArgumentException('Invalid transaction type');
        }

        if ($type === Transaction::EXPENSE) {
            $qb->andWhere($qb->expr()->isInstanceOf('t', Expense::class));

            return;
        }

        $qb->andWhere($qb->expr()->isInstanceOf('t', Income::class));
    }

    private function applyInFilters(
        QueryBuilder $qb,
        ?array $accounts,
        ?array $categories,
        ?array $excludedCategories,
        ?array $debts
    ): void {
        if ($accounts) {
            $qb->andWhere('t.account IN (:accounts)')
                ->setParameter('accounts', $accounts);
        }

        if ($categories) {
            $qb->andWhere('t.category IN (:categories)')
                ->setParameter('categories', $categories);
        }

        if ($excludedCategories) {
            $qb->andWhere('t.category NOT IN (:excludedCategories)')
                ->setParameter('excludedCategories', $excludedCategories);
        }

        if ($debts) {
            $qb->andWhere('t.debt IN (:debts)')
                ->setParameter('debts', $debts);
        }
    }

    private function applyAffectingProfitFilter(QueryBuilder $qb, bool $affectingProfitOnly): void
    {
        if (!$affectingProfitOnly) {
            return;
        }

        // join only when needed
        $qb->innerJoin('t.category', 'c')
            ->andWhere('c.isAffectingProfit = :affectingProfitOnly')
            ->setParameter('affectingProfitOnly', true);
    }

    private function applyNoteFilter(QueryBuilder $qb, ?string $note): void
    {
        if (!is_string($note)) {
            return;
        }

        $needle = trim($note);
        if ($needle === '') {
            return;
        }

        // escape LIKE special chars: \ % _
        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle);

        $qb->andWhere('t.note LIKE :note')
            ->setParameter('note', '%'.$needle.'%');
    }

    private function applyAmountRangeFilter(QueryBuilder $qb, ?float $amountGte, ?float $amountLte): void
    {
        if ($amountGte !== null) {
            $qb->andWhere('t.amount >= :amountGte')
                ->setParameter('amountGte', $amountGte);
        }

        if ($amountLte !== null) {
            $qb->andWhere('t.amount <= :amountLte')
                ->setParameter('amountLte', $amountLte);
        }
    }

    private function applyExecutedAtRange(
        QueryBuilder $qb,
        ?CarbonInterface $after,
        ?CarbonInterface $before
    ): void {
        if ($after !== null) {
            $qb->andWhere('t.executedAt >= :afterStart')
                ->setParameter('afterStart', $after->copy()->startOfDay());
        }

        if ($before !== null) {
            $qb->andWhere('t.executedAt < :beforeEndExclusive')
                ->setParameter('beforeEndExclusive', $before->copy()->addDay()->startOfDay());
        }
    }

    private function applyCurrencyFilter(QueryBuilder $qb, ?array $currencies): void
    {
        if (!$currencies) {
            return;
        }

        $normalized = [];
        foreach ($currencies as $c) {
            if (!is_string($c)) {
                continue;
            }
            $code = strtoupper(trim($c));
            if ($code === '') {
                continue;
            }
            if (!preg_match('/^[A-Z]{3}$/', $code)) {
                throw new InvalidArgumentException('Invalid currency');
            }
            $normalized[] = $code;
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return;
        }

        $qb->innerJoin('t.account', 'a')
            ->andWhere('a.currency IN (:currencies)')
            ->setParameter('currencies', $normalized);
    }
}
