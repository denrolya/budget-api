<?php

namespace App\Repository;

use App\Entity\IncomeCategory;
use Carbon\CarbonInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method IncomeCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method IncomeCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method IncomeCategory[]    findAll()
 * @method IncomeCategory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IncomeCategoryRepository extends CategoryRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncomeCategory::class);
    }

    public function findCreatedWithinPeriod(CarbonInterface $from, CarbonInterface $to)
    {
        $qb = $this->createQueryBuilder('c')
            ->where('DATE(c.createdAt) >= :from')
            ->setParameter('from', $from->toDateString())
            ->andWhere('DATE(c.createdAt) <= :to')
            ->setParameter('to', $to->toDateString());

        return $qb->getQuery()->getResult();
    }
}
