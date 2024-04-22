<?php

namespace App\Repository;

use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
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
            'isTechnical' => false,
            'isAffectingProfit' => true,
        ], $orderBy, $limit, $offset);
    }

    public function getCategoriesWithDescendantsByType(?array $categories = [], string $type = null): array
    {
        $types = $type ? [$type] : [TransactionInterface::EXPENSE, TransactionInterface::INCOME];
        $result = [];

        foreach ($types as $t) {
            $repo = $this
                ->getEntityManager()
                ->getRepository(
                    $t === TransactionInterface::EXPENSE ? ExpenseCategory::class : IncomeCategory::class
                );

            if (empty($categories)) {
                $result[] = $repo->findBy(['root' => null, 'isTechnical' => false, 'isAffectingProfit' => true]);
            } else {
                $foundCategories = $repo->findBy(['id' => $categories]);

                foreach ($foundCategories as $category) {
                    $result[] = $category->getDescendantsFlat()->toArray();
                }
            }
        }

        return array_merge(...$result);
    }

    public function calculateValue(
        Category|int $category,
        string $currency,
        ?CarbonInterface $after,
        ?CarbonInterface $before
    ): ?float
    {
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

        $categoryIds = [
            $category->getId(),
            ...$category->getDescendantsFlat()->map(static fn(Category $category) => $category->getId())->toArray(),
        ];
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

