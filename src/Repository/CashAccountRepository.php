<?php

namespace App\Repository;

use App\Entity\CashAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method CashAccount|null find($id, $lockMode = null, $lockVersion = null)
 * @method CashAccount|null findOneBy(array $criteria, array $orderBy = null)
 * @method CashAccount[]    findAll()
 * @method CashAccount[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CashAccountRepository extends ServiceEntityRepository
{
    private const ALIAS = 'a';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashAccount::class);
    }
}
