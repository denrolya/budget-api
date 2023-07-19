<?php

namespace App\Repository;

use App\Entity\Transfer;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Transfer|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transfer|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transfer[]    findAll()
 * @method Transfer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TransferRepository extends ServiceEntityRepository
{
    public const LIMIT = 20;
    public const OFFSET = 0;
    public const ORDER_FIELD = 'executedAt';
    public const ORDER = 'DESC';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    /**
     * @param null|CarbonInterface $after
     * @param null|CarbonInterface $before
     * @param array|null $accounts
     * @param int|null $limit
     * @param int $offset
     * @param string $orderField
     * @param string $order
     * @return Paginator
     */
    public function getPaginator(?CarbonInterface $after, ?CarbonInterface $before, ?array $accounts = null, ?int $limit = self::LIMIT, int $offset = self::OFFSET, string $orderField = self::ORDER_FIELD, string $order = self::ORDER): Paginator
    {
        $qb = $this->getBaseQueryBuilder($after, $before, $accounts, $limit, $offset, $orderField, $order);

        return new Paginator(
            $qb->getQuery()->setHydrationMode(Query::HYDRATE_OBJECT),
            true
        );
    }

    /**
     * @param null|CarbonInterface $after
     * @param null|CarbonInterface $before
     * @param array|null $accounts
     * @param int|null $limit
     * @param int $offset
     * @param string $orderField
     * @param string $order
     *
     * @return QueryBuilder
     */
    private function getBaseQueryBuilder(?CarbonInterface $after, ?CarbonInterface $before, ?array $accounts = null, ?int $limit = self::LIMIT, int $offset = self::OFFSET, string $orderField = self::ORDER_FIELD, string $order = self::ORDER): QueryBuilder
    {
        $qb = $this
            ->createQueryBuilder('t')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->leftJoin('t.after', 'fa')
            ->leftJoin('t.before', 'ta')
            ->orderBy("t.$orderField", $order);

        if($after) {
            $qb->andWhere('DATE(t.executedAt) >= :after')
                ->setParameter('after', $after->toDateString());
        }

        if($before) {
            $qb->andWhere('DATE(t.executedAt) <= :before')
                ->setParameter('before', $before->toDateString());
        }


        if(!empty($accounts)) {
            $qb->andWhere('(fa.name IN (:accounts) OR ta.name IN (:accounts))')
                ->setParameter('accounts', $accounts);
        }

        return $qb;
    }
}
