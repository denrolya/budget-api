<?php

namespace App\Repository;

use App\Entity\BankCardAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method BankCardAccount|null find($id, $lockMode = null, $lockVersion = null)
 * @method BankCardAccount|null findOneBy(array $criteria, array $orderBy = null)
 * @method BankCardAccount[]    findAll()
 * @method BankCardAccount[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BankCardAccountRepository extends ServiceEntityRepository
{
    private const ALIAS = 'a';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankCardAccount::class);
    }
}
