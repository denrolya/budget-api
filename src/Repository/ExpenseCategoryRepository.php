<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExpenseCategory;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ExpenseCategory|null find($id, $lockMode = null, $lockVersion = null)
 * @method ExpenseCategory|null findOneBy(array $criteria, array $orderBy = null)
 * @method ExpenseCategory[] findAll()
 * @method ExpenseCategory[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 * @method ExpenseCategory|null findOneByName(string $name)
 */
class ExpenseCategoryRepository extends CategoryRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseCategory::class);
    }
}
