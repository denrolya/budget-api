<?php

namespace App\Repository;

use App\Entity\ExchangeRateSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeInterface;

/**
 * @extends ServiceEntityRepository<ExchangeRateSnapshot>
 *
 * @method ExchangeRateSnapshot|null find($id, $lockMode = null, $lockVersion = null)
 * @method ExchangeRateSnapshot|null findOneBy(array $criteria, array $orderBy = null)
 * @method ExchangeRateSnapshot[]    findAll()
 * @method ExchangeRateSnapshot[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ExchangeRateSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRateSnapshot::class);
    }

    public function findExactSnapshot(DateTimeInterface $datetime): ?ExchangeRateSnapshot
    {
        return $this->findOneBy(['effectiveAt' => $datetime]);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function findClosestSnapshot(DateTimeInterface $datetime): ?ExchangeRateSnapshot
    {
        $qb = $this->createQueryBuilder('s');
        $qb->where('s.effectiveAt <= :datetime')
            ->setParameter('datetime', $datetime)
            ->orderBy('s.effectiveAt', 'DESC')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findSnapshotsInRange(DateTimeInterface $fromDateTime, DateTimeInterface $toDateTime): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.effectiveAt >= :from')
            ->andWhere('s.effectiveAt <= :to')
            ->setParameter('from', $fromDateTime)
            ->setParameter('to', $toDateTime)
            ->orderBy('s.effectiveAt', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
