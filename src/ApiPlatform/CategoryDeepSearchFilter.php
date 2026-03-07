<?php

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Category;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * Filters transactions by category and all its descendants.
 */
final class CategoryDeepSearchFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'categoryDeep';

    /**
     * @inheritDoc
     */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PROPERTY_NAME . '[]' => [
                'property' => null,
                'type' => Type::BUILTIN_TYPE_ARRAY,
                'required' => false,
                'schema' => [
                    'type' => Type::BUILTIN_TYPE_ARRAY,
                    'items' => [
                        'type' => Type::BUILTIN_TYPE_INT,
                    ],
                ],
                'openapi' => [
                    'name' => self::PROPERTY_NAME,
                    'description' => 'Filter by categories and their descendants',
                    'type' => Type::BUILTIN_TYPE_ARRAY,
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function filterProperty(
        string                      $property,
        $value,
        QueryBuilder                $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string                      $resourceClass,
        ?Operation                  $operation = null,
        array                       $context = []
    ): void {
        if ($property !== self::PROPERTY_NAME) {
            return;
        }

        if ($value !== [] && $value !== null) {
            $categories = [];
            foreach ($value as $categoryId) {
                $em = $queryBuilder->getEntityManager();
                if (!$category = $em->getRepository(Category::class)->find($categoryId)) {
                    continue;
                }
                $categories = [...$categories, ...$category->getDescendantsFlat()];
            }

            $alias = $queryBuilder->getRootAliases()[0];

            if ($categories !== []) {
                $queryBuilder->andWhere("$alias.category IN (:categories)")
                    ->setParameter('categories', array_map(static function (Category $category) {
                        return $category->getId();
                    }, $categories));
            }
        }
    }
}
