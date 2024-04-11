<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountLogEntry;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method AccountLogEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccountLogEntry|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccountLogEntry[]    findAll()
 * @method AccountLogEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccountLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountLogEntry::class);
    }

    public function findWithinPeriod(
        CarbonInterface $after,
        ?CarbonInterface $before = null,
        int $limit = null,
        Account $account = null
    ): array {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('DATE(l.createdAt) >= :after')
            ->setParameter('after', $after->toDateString());

        if ($before) {
            $qb
                ->andWhere('DATE(l.createdAt) <= :before')
                ->setParameter('before', $before->toDateString());
        }

        if ($account) {
            $qb
                ->andWhere('l.account = :account')
                ->setParameter('account', $account);
        }

        return $qb
            ->setMaxResults($limit)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
