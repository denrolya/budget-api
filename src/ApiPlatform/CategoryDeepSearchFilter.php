<?php

namespace App\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use App\Entity\Category;
use App\Service\AssetsManager;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * TODO: Document properly
 */
final class CategoryDeepSearchFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'category_deep';

    private AssetsManager $assetsManager;

    public function __construct(
        AssetsManager          $assetsManager,
        ManagerRegistry        $managerRegistry,
        ?RequestStack          $requestStack = null,
        LoggerInterface        $logger = null,
        array                  $properties = null,
        NameConverterInterface $nameConverter = null,
    )
    {
        parent::__construct($managerRegistry, $requestStack, $logger, $properties, $nameConverter);

        $this->assetsManager = $assetsManager;
    }

    /**
     * @inheritDoc
     */
    protected function filterProperty(
        string                      $property,
        mixed                       $value,
        QueryBuilder                $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string                      $resourceClass,
        string                      $operationName = null
    ): void
    {
        if($property !== self::PROPERTY_NAME) {
            return;
        }

        if(!empty($value)) {
            $categories = [];
            foreach($value as $categoryId) {
                $em = $queryBuilder->getEntityManager();
                if(!$category = $em->getRepository(Category::class)->find($categoryId)) {
                    continue;
                }
                $categories = [...$categories, ...$category->getDescendantsFlat()];
            }

            $alias = $queryBuilder->getRootAliases()[0];

            if(!empty($categories)) {
                $queryBuilder->andWhere("$alias.category IN (:categories)")
                    ->setParameter('categories', array_map(static function (Category $category) {
                        return $category->getId();
                    }, $categories));
            }
        }
    }

    /**
     * @inheritDoc
     */
    #[ArrayShape([self::PROPERTY_NAME => 'array'])]
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
                    'description' => 'Filter by categories and theirs descendants',
                    'type' => Type::BUILTIN_TYPE_ARRAY,
                ],
            ],
        ];
    }
}
