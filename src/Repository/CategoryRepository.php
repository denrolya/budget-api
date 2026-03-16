<?php

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

            if ($categories === null || $categories === []) {
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
}

