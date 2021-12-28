<?php

namespace App\Repository;

use App\Entity\ExpenseCategory;
use Carbon\CarbonInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ExpenseCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method ExpenseCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method ExpenseCategory[]    findAll()
 * @method ExpenseCategory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ExpenseCategoryRepository extends CategoryRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseCategory::class);
    }

    /**
     * @param CarbonInterface $from
     * @param CarbonInterface $to
     * @return ExpenseCategory[]|array
     */
    public function findCreatedWithinPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('DATE(c.createdAt) >= :from')
            ->setParameter('from', $from->toDateString())
            ->andWhere('DATE(c.createdAt) <= :to')
            ->setParameter('to', $to->toDateString());

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array $tags
     * @return ExpenseCategory[]|array
     */
    public function findByTags(array $tags): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.tags', 't')
            ->where('t.name IN (:tags)')
            ->setParameter('tags', $tags);

        return $qb->getQuery()->getResult();
    }
}
