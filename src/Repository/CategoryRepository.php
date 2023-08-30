<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\IncomeCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Category|null find($id, $lockMode = null, $lockVersion = null)
 * @method Category|null findOneBy(array $criteria, array $orderBy = null)
 * @method Category[]    findAll()
 * @method Category[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, ?string $classname = null)
    {
        $class = (!$classname) ? Category::class : $classname;

        parent::__construct($registry, $class);
    }

    public function findByTypes(array $types)
    {
        $qb = $this->createQueryBuilder('c');

        if(!empty($types) && count($types) === 1) {
            if(in_array(Category::EXPENSE_CATEGORY_TYPE, $types, true)) {
                $qb->andWhere('c INSTANCE OF :expenseType')
                    ->setParameter('expenseType', $this->getEntityManager()->getClassMetadata(Category::class));
            } elseif(in_array(Category::INCOME_CATEGORY_TYPE, $types, true)) {
                $qb->andWhere('c INSTANCE OF :incomeType')
                    ->setParameter('incomeType', $this->getEntityManager()->getClassMetadata(IncomeCategory::class));
            }
        }

        return $qb->getQuery()->getResult();
    }

    public function findRootCategories(?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->findBy([
            'root' => null,
            'isTechnical' => false,
            'isAffectingProfit' => true,
        ], $orderBy, $limit, $offset);
    }
}

