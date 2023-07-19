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

    public function findCreatedWithinPeriod(CarbonInterface $after, CarbonInterface $before)
    {
        $qb = $this->createQueryBuilder('c')
            ->where('DATE(c.createdAt) >= :after')
            ->setParameter('after', $after->toDateString())
            ->andWhere('DATE(c.createdAt) <= :before')
            ->setParameter('before', $before->toDateString());

        return $qb->getQuery()->getResult();
    }
}
