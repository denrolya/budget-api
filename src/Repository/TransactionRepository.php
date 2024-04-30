<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountLogEntry;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use App\Pagination\Paginator;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Collection;
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

    public function __construct(ManagerRegistry $registry, ?string $classname = null)
    {
        $class = (!$classname) ? Transaction::class : $classname;

        parent::__construct($registry, $class);
    }

    /**
     * @param CarbonInterface|null $after
     * @param CarbonInterface|null $before
     * @param string|null $type
     * @param array<Category|int>|null $categories
     * @param array<Account|int>|null $accounts
     * @param array<Category|int>|null $excludedCategories
     * @param bool $affectingProfitOnly
     * @param bool $onlyDrafts
     * @param string $orderField
     * @param string $order
     * @return Collection|array
     */
    public function getList(
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = [],
        bool $affectingProfitOnly = true,
        bool $onlyDrafts = false,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER
    ): Collection|array {
        return $this
            ->getBaseQueryBuilder(
                after: $after,
                before: $before,
                affectingProfitOnly: $affectingProfitOnly,
                type: $type,
                categories: $categories,
                accounts: $accounts,
                excludedCategories: $excludedCategories,
                onlyDrafts: $onlyDrafts,
                orderField: $orderField,
                order: $order
            )
            ->getQuery()
            ->getResult();
    }

    public function getListQueryBuilder(
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = [],
        bool $affectingProfitOnly = true,
        bool $onlyDrafts = false,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER
    ): QueryBuilder {
        return $this
            ->getBaseQueryBuilder(
                $after,
                $before,
                $affectingProfitOnly,
                $type,
                $categories,
                $accounts,
                $excludedCategories,
                $onlyDrafts,
                $orderField,
                $order
            );
    }


    /**
     * @param CarbonInterface|null $after
     * @param CarbonInterface|null $before
     * @param bool $affectingProfitOnly
     * @param string|null $type
     * @param array<Category|int>|null $categories
     * @param array<Account|int>|null $accounts
     * @param array<Category|int>|null $excludedCategories
     * @param bool $onlyDrafts
     * @param string $orderField
     * @param string $order
     * @return QueryBuilder
     */
    protected function getBaseQueryBuilder(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        bool $affectingProfitOnly = false,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = [],
        bool $onlyDrafts = false,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER
    ): QueryBuilder {
        $qb = $this
            ->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')
            ->orderBy("t.$orderField", $order)
            ->addOrderBy('t.id', $order)
            ->andWhere('t.isDraft = :onlyDrafts')
            ->setParameter('onlyDrafts', $onlyDrafts);

        if ($affectingProfitOnly) {
            $qb->andWhere('c.isAffectingProfit = :affectingProfitOnly')
                ->setParameter('affectingProfitOnly', $affectingProfitOnly);
        }

        if ($after) {
            $qb->andWhere('DATE(t.executedAt) >= :after')
                ->setParameter('after', $after->toDateString());
        }

        if ($before) {
            $qb->andWhere('DATE(t.executedAt) <= :before')
                ->setParameter('before', $before->toDateString());
        }

        if ($type && !in_array($type, [Transaction::EXPENSE, Transaction::INCOME], true)) {
            throw new InvalidArgumentException('Invalid transaction type');
        }

        if ($type === Transaction::EXPENSE) {
            $qb->andWhere('t INSTANCE OF :type')
                ->setParameter('type', $this->getEntityManager()->getClassMetadata(Expense::class));
        } elseif ($type === Transaction::INCOME) {
            $qb->andWhere('t INSTANCE OF :type')
                ->setParameter('type', $this->getEntityManager()->getClassMetadata(Income::class));
        }

        if (!empty($accounts)) {
            $qb->andWhere('t.account IN (:accounts)')
                ->setParameter('accounts', $accounts);
        }

        if (!empty($categories)) {
            $qb->andWhere('t.category IN (:categories)')
                ->setParameter('categories', $categories);
        }

        if (!empty($excludedCategories)) {
            $qb->andWhere('t.category NOT IN (:excludedCategories)')
                ->setParameter('excludedCategories', $excludedCategories);
        }

        return $qb;
    }

    /**
     * TODO: Replace $offset with $page
     */
    public function getPaginator(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        bool $affectingProfitOnly = false,
        ?string $type = null,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = [],
        bool $onlyDrafts = false,
        ?int $limit = Paginator::PER_PAGE,
        int $offset = 0,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER
    ): Paginator {
        $qb = $this->getBaseQueryBuilder(
            $after,
            $before,
            $affectingProfitOnly,
            $type,
            $categories,
            $accounts,
            $excludedCategories,
            $onlyDrafts,
            $orderField,
            $order
        );

        return (new Paginator($qb, $limit))->paginate(($offset / $limit) + 1);
    }

    public function findWithinPeriodByAccount(
        Account $account,
        CarbonInterface $after,
        ?CarbonInterface $before = null
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.account', 'a')
            ->andWhere('DATE(t.executedAt) >= :after')
            ->andWhere('a.id = :account')
            ->setParameter('after', $after->toDateString())
            ->setParameter('account', $account->getId());

        if ($before) {
            $qb
                ->andWhere('DATE(t.executedAt) <= :before')
                ->setParameter('before', $before->toDateString());
        }

        return $qb
            ->orderBy('t.'.self::ORDER_FIELD, 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWithinPeriod(CarbonInterface $after, ?CarbonInterface $before = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('DATE(t.executedAt) >= :after')
            ->setParameter('after', $after->toDateString());

        if ($before) {
            $qb
                ->andWhere('DATE(t.executedAt) <= :before')
                ->setParameter('before', $before->toDateString());
        }

        return $qb
            ->orderBy('t.'.self::ORDER_FIELD, self::ORDER)
            ->getQuery()
            ->getResult();
    }

    public function findBeforeLastLog(Account $account): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.account', 'a')
            ->where('a.id = :account')
            ->setParameter('account', $account)
            ->orderBy('t.executedAt', 'DESC');

        $lastLogEntry = $this->getEntityManager()->getRepository(AccountLogEntry::class)->findOneBy([
            'account' => $account,
        ], [
            'createdAt' => 'DESC',
        ]);

        if ($lastLogEntry) {
            $qb
                ->andWhere('t.executedAt > :date')
                ->setParameter('date', $lastLogEntry->getCreatedAt()->toDateTimeString());
        }


        return $qb->getQuery()->getResult();
    }
}
