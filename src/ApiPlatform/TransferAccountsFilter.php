<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * Filter for transfers where either 'from' or 'to' account is in the given list of account IDs.
 */
final class TransferAccountsFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'accounts';

    /**
     * Returns the OpenAPI description for the filter.
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
                    'description' => 'Filter by account IDs for either from or to accounts',
                    'type' => Type::BUILTIN_TYPE_ARRAY,
                ],
            ],
        ];
    }

    /**
     * Applies the filter to the query.
     */
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (self::PROPERTY_NAME !== $property) {
            return;
        }

        if (\is_array($value) && [] !== $value) {
            $alias = $queryBuilder->getRootAliases()[0];

            $queryBuilder
                ->andWhere("$alias.from IN (:accountIds) OR $alias.to IN (:accountIds)")
                ->setParameter('accountIds', $value);
        }
    }
}
