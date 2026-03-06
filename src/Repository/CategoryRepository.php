<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use Carbon\CarbonInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Category|null find($id, $lockMode = null, $lockVersion = null)
 * @method Category|null findOneBy(array $criteria, array $orderBy = null)
 * @method Category[]    findAll()
 * @method Category[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, ?string $classname = null)
    {
        $class = (!$classname) ? Category::class : $classname;

        parent::__construct($registry, $class);
    }

    public function findRootCategories(?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->findBy([
            'root' => null,
            'isAffectingProfit' => true,
        ], $orderBy, $limit, $offset);
    }

    public function getCategoriesWithDescendantsByType(?array $categories = [], string $type = null): array
    {
        $types = $type ? [$type] : [Transaction::EXPENSE, Transaction::INCOME];
        $result = [];
        $map = $this->buildDescendantMap();

        foreach ($types as $t) {
            $repo = $this
                ->getEntityManager()
                ->getRepository(
                    $t === Transaction::EXPENSE ? ExpenseCategory::class : IncomeCategory::class
                );

            if (empty($categories)) {
                $result[] = $repo->findBy(['root' => null, 'isAffectingProfit' => true]);
            } else {
                $foundCategories = $repo->findBy(['id' => $categories]);

                $descendantIds = [];
                foreach ($foundCategories as $category) {
                    foreach ($map[$category->getId()] ?? [$category->getId()] as $id) {
                        $descendantIds[] = $id;
                    }
                }

                if ($descendantIds) {
                    $result[] = $this->findBy(['id' => array_unique($descendantIds)]);
                }
            }
        }

        return array_merge(...$result);
    }

    /**
     * Builds a map of categoryId → [self + all descendant ids] using a single DB query.
     * Replaces recursive PHP getDescendantsFlat() calls throughout the codebase.
     */
    public function buildDescendantMap(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'IDENTITY(c.parent) AS parent_id')
            ->getQuery()
            ->getScalarResult();

        $childrenOf = [];
        $allIds = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $allIds[] = $id;
            if ($row['parent_id'] !== null) {
                $childrenOf[(int) $row['parent_id']][] = $id;
            }
        }

        $map = [];
        foreach ($allIds as $id) {
            $map[$id] = $this->collectDescendantIds($id, $childrenOf);
        }

        return $map;
    }

    private function collectDescendantIds(int $id, array &$childrenOf): array
    {
        $ids = [$id];
        foreach ($childrenOf[$id] ?? [] as $childId) {
            array_push($ids, ...$this->collectDescendantIds($childId, $childrenOf));
        }

        return $ids;
    }

    public function calculateValue(
        Category|int $category,
        string $currency,
        ?CarbonInterface $after,
        ?CarbonInterface $before
    ): ?float {
        if (is_int($category)) {
            $category = $this->find($category);
            if (!$category) {
                throw new \InvalidArgumentException('Invalid category ID');
            }
        }

        $qb = $this
            ->getEntityManager()
            ->createQueryBuilder('t')
            ->from(Transaction::class, 't')
            ->select('SUM(JSON_EXTRACT(t.convertedValues, :baseCurrency))')
            ->where('t.category = :categoryId')
            ->setParameter('baseCurrency', '$.'.$currency)
            ->setParameter('categoryId', $category->getId());

        if (isset($after, $before)) {
            $qb->andWhere('DATE(t.executedAt) >= :after')
                ->setParameter('after', $after)
                ->andWhere('DATE(t.executedAt) <= :before')
                ->setParameter('before', $before);
        }

        return $qb->getQuery()->getSingleScalarResult() ?? 0;
    }

    /**
     * New method to calculate category total value using SQL
     *
     * @throws NonUniqueResultException
     * @throws NoResultException
     */
    public function calculateTotalValue(
        $category,
        string $currency,
        ?CarbonInterface $after,
        ?CarbonInterface $before
    ): float {
        if (is_int($category)) {
            $category = $this->find($category);
            if (!$category) {
                throw new \InvalidArgumentException('Invalid category ID');
            }
        }

        $map = $this->buildDescendantMap();
        $categoryIds = $map[$category->getId()] ?? [$category->getId()];
        $qb = $this
            ->getEntityManager()
            ->createQueryBuilder('t')
            ->from(Transaction::class, 't')
            ->select('SUM(JSON_EXTRACT(t.convertedValues, :baseCurrency))')
            ->where('t.category IN (:categoryIds)')
            ->setParameter('baseCurrency', '$.'.$currency)
            ->setParameter('categoryIds', $categoryIds);

        if (isset($after, $before)) {
            $qb->andWhere('DATE(t.executedAt) >= :after')
                ->setParameter('after', $after)
                ->andWhere('DATE(t.executedAt) <= :before')
                ->setParameter('before', $before);
        }

        return $qb->getQuery()->getSingleScalarResult() ?? 0;
    }
}

