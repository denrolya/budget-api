<?php

namespace App\Repository;

use App\Entity\BankIntegration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BankIntegration>
 */
class BankIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankIntegration::class);
    }

    /** @return BankIntegration[] */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('bi')
            ->andWhere('bi.owner = :user')
            ->andWhere('bi.isActive = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
