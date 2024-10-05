<?php

namespace App\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * Filter for transfers where either 'from' or 'to' account is in the given list of account IDs.
 */
final class TransferAccountsFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'accounts';

    public function __construct(
        ManagerRegistry $managerRegistry,
        ?RequestStack $requestStack = null,
        LoggerInterface $logger = null,
        array $properties = null,
        NameConverterInterface $nameConverter = null
    ) {
        parent::__construct($managerRegistry, $requestStack, $logger, $properties, $nameConverter);
    }

    /**
     * Returns the OpenAPI description for the filter.
     */
    #[ArrayShape([self::PROPERTY_NAME => 'array'])]
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PROPERTY_NAME.'[]' => [
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
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        string $operationName = null
    ): void {
        // Check if the property matches the filter's property name
        if ($property !== self::PROPERTY_NAME) {
            return;
        }

        // Ensure the filter value is an array and not empty
        if (!empty($value) && is_array($value)) {
            $alias = $queryBuilder->getRootAliases()[0];

            // Add conditions for both 'from' and 'to' accounts
            $queryBuilder
                ->andWhere("$alias.from IN (:accountIds) OR $alias.to IN (:accountIds)")
                ->setParameter('accountIds', $value);
        }
    }
}
