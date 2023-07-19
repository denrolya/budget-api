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
     * @param CarbonInterface $after
     * @param CarbonInterface $before
     * @return ExpenseCategory[]|array
     */
    public function findCreatedWithinPeriod(CarbonInterface $after, CarbonInterface $before): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('DATE(c.createdAt) >= :after')
            ->setParameter('after', $after->toDateString())
            ->andWhere('DATE(c.createdAt) <= :before')
            ->setParameter('before', $before->toDateString());

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array $tags
     * @return array
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
