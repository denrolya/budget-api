<?php

namespace App\Repository;

use App\Entity\BudgetGoal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method BudgetGoal|null find($id, $lockMode = null, $lockVersion = null)
 * @method BudgetGoal|null findOneBy(array $criteria, array $orderBy = null)
 * @method BudgetGoal[]    findAll()
 * @method BudgetGoal[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BudgetGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetGoal::class);
    }
}
