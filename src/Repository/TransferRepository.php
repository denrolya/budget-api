<?php

declare(strict_types=1);

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
 * @method Transfer[] findAll()
 * @method Transfer[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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
     * Use countForLedger() to get the true total without this limit.
     *
     * @return array<Transfer>
     */
    public function getListForLedger(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        ?array $accounts = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        int $limit = 10000,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
    ): array {
        return $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            accounts: $accounts,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
            orderField: $orderField,
            order: $order,
        )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the exact count of transfers matching the ledger filters.
     */
    public function countForLedger(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        ?array $accounts = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
    ): int {
        return (int) $this->getBaseQueryBuilder(
            after: $after,
            before: $before,
            accounts: $accounts,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
        )
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Single source of truth for listing filters.
     */
    private function getBaseQueryBuilder(
        ?CarbonInterface $after = null,
        ?CarbonInterface $before = null,
        ?array $accounts = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        string $orderField = self::ORDER_FIELD,
        string $order = self::ORDER,
    ): QueryBuilder {
        $orderField = $this->assertOrderField($orderField);
        $order = $this->assertOrderDirection($order);

        $queryBuilder = $this->createQueryBuilder('t')
            ->orderBy("t.$orderField", $order)
            ->addOrderBy('t.id', $order);

        $this->applyExecutedAtRange($queryBuilder, $after, $before);
        $this->applyAccountsFilter($queryBuilder, $accounts);
        $this->applyNoteFilter($queryBuilder, $note);
        $this->applyAmountRangeFilter($queryBuilder, $amountGte, $amountLte);

        return $queryBuilder;
    }

    private function assertOrderField(string $orderField): string
    {
        if (!\in_array($orderField, self::ALLOWED_ORDER_FIELDS, true)) {
            throw new InvalidArgumentException('Invalid order field');
        }

        return $orderField;
    }

    private function assertOrderDirection(string $order): string
    {
        $order = strtoupper($order);

        if (!\in_array($order, self::ALLOWED_ORDER_DIRECTIONS, true)) {
            throw new InvalidArgumentException('Invalid order direction');
        }

        return $order;
    }

    private function applyExecutedAtRange(
        QueryBuilder $queryBuilder,
        ?CarbonInterface $after,
        ?CarbonInterface $before,
    ): void {
        if (null !== $after) {
            $queryBuilder->andWhere('t.executedAt >= :afterStart')
                ->setParameter('afterStart', $after->copy()->startOfDay());
        }

        if (null !== $before) {
            $queryBuilder->andWhere('t.executedAt < :beforeEndExclusive')
                ->setParameter('beforeEndExclusive', $before->copy()->addDay()->startOfDay());
        }
    }

    private function applyAccountsFilter(QueryBuilder $queryBuilder, ?array $accounts): void
    {
        if (null === $accounts || [] === $accounts) {
            return;
        }

        $queryBuilder->leftJoin('t.from', 'tf_from')
            ->leftJoin('t.to', 'tf_to')
            ->andWhere('tf_from.id IN (:accounts) OR tf_to.id IN (:accounts)')
            ->setParameter('accounts', $accounts);
    }

    private function applyAmountRangeFilter(QueryBuilder $queryBuilder, ?float $amountGte, ?float $amountLte): void
    {
        if (null !== $amountGte) {
            $queryBuilder->andWhere('t.amount >= :amountGte')
                ->setParameter('amountGte', $amountGte);
        }

        if (null !== $amountLte) {
            $queryBuilder->andWhere('t.amount <= :amountLte')
                ->setParameter('amountLte', $amountLte);
        }
    }

    private function applyNoteFilter(QueryBuilder $queryBuilder, ?string $note): void
    {
        if (!\is_string($note)) {
            return;
        }

        $needle = trim($note);
        if ('' === $needle) {
            return;
        }

        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle);

        $queryBuilder->andWhere('t.note LIKE :note')
            ->setParameter('note', '%' . $needle . '%');
    }
}
