<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Pagination\Paginator;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

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

    public function getPaginator(
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        bool             $affectingProfitOnly = false,
        ?array           $types = [],
        ?array           $categories = [],
        ?array           $accounts = [],
        ?array           $excludedCategories = [],
        bool             $onlyDrafts = false,
        ?int             $limit = Paginator::PAGE_SIZE,
        int              $offset = 0,
        string           $orderField = self::ORDER_FIELD,
        string           $order = self::ORDER
    ): Paginator
    {
        $qb = $this->getBaseQueryBuilder($from, $to, $affectingProfitOnly, $types, $categories, $accounts, $excludedCategories, $onlyDrafts, $orderField, $order);

        return (new Paginator($qb, $limit))->paginate(($offset / $limit) + 1);
    }

    protected function getBaseQueryBuilder(
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        bool             $affectingProfitOnly = false,
        ?array           $types = [],
        ?array           $categories = [],
        ?array           $accounts = [],
        ?array           $excludedCategories = [],
        bool             $onlyDrafts = false,
        string           $orderField = self::ORDER_FIELD,
        string           $order = self::ORDER
    ): QueryBuilder
    {
        $qb = $this
            ->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')
            ->orderBy("t.$orderField", $order)
            ->andWhere('t.isDraft = :onlyDrafts')
            ->setParameter('onlyDrafts', $onlyDrafts);

        if($affectingProfitOnly) {
            $qb->andWhere('c.isAffectingProfit = :affectingProfitOnly')
                ->setParameter('affectingProfitOnly', $affectingProfitOnly);
        }

        if($from) {
            $qb->andWhere('DATE(t.executedAt) >= :from')
                ->setParameter('from', $from->toDateString());
        }

        if($to) {
            $qb->andWhere('DATE(t.executedAt) <= :to')
                ->setParameter('to', $to->toDateString());
        }

        if(!empty($types) && count($types) === 1) {
            if(in_array(TransactionInterface::EXPENSE, $types)) {
                $qb->andWhere('t INSTANCE OF :expenseType')
                    ->setParameter('expenseType', $this->getEntityManager()->getClassMetadata(Expense::class));
            } elseif(in_array(TransactionInterface::INCOME, $types)) {
                $qb->andWhere('t INSTANCE OF :incomeType')
                    ->setParameter('incomeType', $this->getEntityManager()->getClassMetadata(Income::class));
            }
        }

        if(!empty($accounts)) {
            $qb->leftJoin('t.account', 'a')
                ->andWhere('a.name IN (:accounts)')
                ->setParameter('accounts', $accounts);
        }

        if(!empty($categories)) {
            $qb->andWhere('c.name IN (:categories)')
                ->setParameter('categories', $categories);
        }

        if(!empty($excludedCategories)) {
            $qb->andWhere('c.name NOT IN (:excludedCategories)')
                ->setParameter('excludedCategories', $excludedCategories);
        }

        return $qb;
    }

    public function findWithinPeriodByAccount(Account $account, CarbonInterface $from, ?CarbonInterface $to = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.account', 'a')
            ->andWhere('DATE(t.executedAt) >= :from')
            ->andWhere('a.id = :account')
            ->setParameter('from', $from->toDateString())
            ->setParameter('account', $account->getId());

        if($to) {
            $qb
                ->andWhere('DATE(t.executedAt) <= :to')
                ->setParameter('to', $to->toDateString());
        }

        return $qb
            ->orderBy('t.' . self::ORDER_FIELD, 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWithinPeriod(CarbonInterface $from, ?CarbonInterface $to = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('DATE(t.executedAt) >= :from')
            ->setParameter('from', $from->toDateString());

        if($to) {
            $qb
                ->andWhere('DATE(t.executedAt) <= :to')
                ->setParameter('to', $to->toDateString());
        }

        return $qb
            ->orderBy('t.' . self::ORDER_FIELD, self::ORDER)
            ->getQuery()
            ->getResult();
    }

    public function findBeforeLastLog(Account $account): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.account', 'a')
            ->where('a.id = :account')
            ->andWhere('t.canceledAt IS NULL')
            ->setParameter('account', $account)
            ->orderBy('t.executedAt', 'DESC');

        if($lastLogEntry = $account->getLatestLogEntry()) {
            $qb
                ->andWhere('t.executedAt > :date')
                ->setParameter('date', $lastLogEntry->getCreatedAt()->toDateTimeString());
        }


        return $qb->getQuery()->getResult();
    }
}
