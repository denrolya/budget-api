<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Repository\CategoryRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * Filters transactions by category and all its descendants.
 * Uses CategoryRepository::buildDescendantMap() for a single-query hierarchy lookup.
 */
final class CategoryDeepSearchFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'categoryDeep';

    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        \Doctrine\Persistence\ManagerRegistry $managerRegistry,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?array $properties = null,
    ) {
        parent::__construct($managerRegistry, $logger, $properties);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PROPERTY_NAME . '[]' => [
                'property' => '',
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
     * @param array<string, mixed> $context
     */
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (self::PROPERTY_NAME !== $property) {
            return;
        }

        if ([] === $value || null === $value) {
            return;
        }

        $requestedIds = array_filter(
            array_map('intval', (array) $value),
            static fn (int $identifier): bool => $identifier > 0,
        );

        if ([] === $requestedIds) {
            return;
        }

        $descendantMap = $this->categoryRepository->buildDescendantMap();

        $allCategoryIds = [];
        foreach ($requestedIds as $categoryId) {
            if (isset($descendantMap[$categoryId])) {
                foreach ($descendantMap[$categoryId] as $descendantId) {
                    $allCategoryIds[$descendantId] = true;
                }
            }
        }

        if ([] === $allCategoryIds) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parameterName = $queryNameGenerator->generateParameterName('categoryDeep');

        $queryBuilder
            ->andWhere(\sprintf('%s.category IN (:%s)', $alias, $parameterName))
            ->setParameter($parameterName, array_keys($allCategoryIds));
    }
}
