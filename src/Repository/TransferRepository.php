<?php

namespace App\Repository;

use App\Entity\Transfer;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;

/**
 * QueryBuilder-backed repository with centralized filter composition.
 *
 * @method Transfer|null find($id, $lockMode = null, $lockVersion = null)
 * @method Transfer|null findOneBy(array $criteria, array $orderBy = null)
 * @method Transfer[]    findAll()
 * @method Transfer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TransferRepository extends ServiceEntityRepository
{
    public const ORDER_FIELD = 'executedAt';
    public const ORDER = 'DESC';

    private const ALLOWED_ORDER_FIELDS = ['executedAt', 'id', 'amount', 'createdAt'];
    private const ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transfer::class);
    }

    /**
     * Returns all transfers matching the given filters, for use in the unified ledger endpoint.
     *
     * @return array<Transfer>
     */
    public function getListForLedger(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        ?array $accounts = null,
        ?string $note = null,
        int $limit = 1000,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
    ): array {
        return $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            accounts: $accounts,
            note: $note,
            orderField: $orderField,
            order: $order,
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Single source of truth for listing filters.
     */
    private function getBaseQueryBuilder(
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        ?array $accounts = null,
        ?string $note = null,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
    ): QueryBuilder {
        $orderField = $this->assertOrderField($orderField);
        $order      = $this->assertOrderDirection($order);

        $qb = $this->createQueryBuilder('t')
            ->orderBy("t.$orderField", $order)
            ->addOrderBy('t.id', $order);

        $this->applyExecutedAtRange($qb, $after, $before);
        $this->applyAccountsFilter($qb, $accounts);
        $this->applyNoteFilter($qb, $note);

        return $qb;
    }

    private function assertOrderField(string $orderField): string
    {
        if (!in_array($orderField, self::ALLOWED_ORDER_FIELDS, true)) {
            throw new InvalidArgumentException('Invalid order field');
        }

        return $orderField;
    }

    private function assertOrderDirection(string $order): string
    {
        $order = strtoupper($order);

        if (!in_array($order, self::ALLOWED_ORDER_DIRECTIONS, true)) {
            throw new InvalidArgumentException('Invalid order direction');
        }

        return $order;
    }

    private function applyExecutedAtRange(
        QueryBuilder $qb,
        ?CarbonInterface $after,
        ?CarbonInterface $before,
    ): void {
        if ($after !== null) {
            $qb->andWhere('t.executedAt >= :afterStart')
                ->setParameter('afterStart', $after->copy()->startOfDay());
        }

        if ($before !== null) {
            $qb->andWhere('t.executedAt < :beforeEndExclusive')
                ->setParameter('beforeEndExclusive', $before->copy()->addDay()->startOfDay());
        }
    }

    private function applyAccountsFilter(QueryBuilder $qb, ?array $accounts): void
    {
        if (empty($accounts)) {
            return;
        }

        $qb->leftJoin('t.from', 'tf_from')
            ->leftJoin('t.to', 'tf_to')
            ->andWhere('tf_from.id IN (:accounts) OR tf_to.id IN (:accounts)')
            ->setParameter('accounts', $accounts);
    }

    private function applyNoteFilter(QueryBuilder $qb, ?string $note): void
    {
        if (!is_string($note)) {
            return;
        }

        $needle = trim($note);
        if ($needle === '') {
            return;
        }

        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle);

        $qb->andWhere('t.note LIKE :note')
            ->setParameter('note', '%' . $needle . '%');
    }
}
