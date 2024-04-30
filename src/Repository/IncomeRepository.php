<?php

namespace App\Repository;

use App\Entity\Income;
use App\Entity\Transaction;
use Carbon\CarbonInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Income|null find($id, $lockMode = null, $lockVersion = null)
 * @method Income|null findOneBy(array $criteria, array $orderBy = null)
 * @method Income[]    findAll()
 * @method Income[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IncomeRepository extends TransactionRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Income::class);
    }

    public function findWithinPeriod(
        CarbonInterface $after,
        ?CarbonInterface $before = null,
        ?int $limit = null,
        bool $affectingProfitOnly = true,
        array $categories = [],
        array $excludedCategories = []
    ): array {
        $qb = $this->getBaseQueryBuilder(
            $after,
            $before,
            $affectingProfitOnly,
            Transaction::INCOME,
            [],
            $categories,
            $excludedCategories
        );

        return $qb->getQuery()->getResult();
    }
}
