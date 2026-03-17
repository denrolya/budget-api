<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IncomeCategory;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method IncomeCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method IncomeCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method IncomeCategory[] findAll()
 * @method IncomeCategory[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IncomeCategoryRepository extends CategoryRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncomeCategory::class);
    }
}
