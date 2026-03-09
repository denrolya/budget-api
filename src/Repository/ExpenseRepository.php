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
}
