<?php

namespace App\Repository;

use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Transaction;
use Carbon\CarbonInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Expense|null find($id, $lockMode = null, $lockVersion = null)
 * @method Expense|null findOneBy(array $criteria, array $orderBy = null)
 * @method Expense[]    findAll()
 * @method Expense[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ExpenseRepository extends TransactionRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    public function getInCategories(array $categories = null): array
    {
        $qb = $this->getBaseQueryBuilder(
            null,
            null,
            true,
            Transaction::EXPENSE,
            [],
            $categories
        );

        return $qb->getQuery()->getResult();
    }

    public function getOnGivenDay(CarbonInterface $day): array
    {
        return $this->findWithinPeriod($day, $day, true, [], [ExpenseCategory::CATEGORY_RENT]);
    }

    public function findWithinPeriod(
        CarbonInterface $after,
        ?CarbonInterface $before = null,
        bool $affectingProfitOnly = true,
        array $categories = [],
        array $excludedCategories = []
    ): array {
        $qb = $this->getBaseQueryBuilder(
            $after,
            $before,
            $affectingProfitOnly,
            Transaction::EXPENSE,
            $categories,
            [],
            $excludedCategories
        );

        return $qb->getQuery()->getResult();
    }

    public function getMonthExpensesInCategory(CarbonInterface $month, array $categories): array
    {
        return $this->findWithinPeriod(
            $month->copy()->startOf('month'),
            $month->copy()->endOf('month'),
            true,
            $categories
        );
    }

    public function findWithCompensations(): array
    {
        return $this
            ->createQueryBuilder('e')
            ->innerJoin('e.compensations', 'c')
            ->addSelect('c')
            ->getQuery()
            ->getResult();
    }
}
