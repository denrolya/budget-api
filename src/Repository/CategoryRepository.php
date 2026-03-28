<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Category|null find($id, $lockMode = null, $lockVersion = null)
 * @method Category|null findOneBy(array $criteria, array $orderBy = null)
 * @method Category[] findAll()
 * @method Category[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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

    public function getCategoriesWithDescendantsByType(?array $categories = [], ?string $type = null): array
    {
        $types = $type ? [$type] : [Transaction::EXPENSE, Transaction::INCOME];
        $result = [];
        $map = $this->buildDescendantMap();

        foreach ($types as $categoryType) {
            $repo = $this
                ->getEntityManager()
                ->getRepository(
                    Transaction::EXPENSE === $categoryType ? ExpenseCategory::class : IncomeCategory::class,
                );

            if (null === $categories || [] === $categories) {
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
     * Returns a flat map of categoryId → parentId (null for root categories).
     * Single DB query; used by StatisticsManager to resolve direct parent-child relationships.
     *
     * @return array<int, int|null>
     */
    public function buildParentMap(): array
    {
        $rows = $this->createQueryBuilder('category')
            ->select('category.id', 'IDENTITY(category.parent) AS parent_id')
            ->getQuery()
            ->getScalarResult();

        $parentMap = [];
        /** @var array{id: int|string, parent_id: int|string|null} $row */
        foreach ($rows as $row) {
            $parentMap[(int) $row['id']] = null !== $row['parent_id'] ? (int) $row['parent_id'] : null;
        }

        return $parentMap;
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
        /** @var array{id: int|string, parent_id: int|string|null} $row */
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $allIds[] = $id;
            if (null !== $row['parent_id']) {
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
}
