<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

final class DiscriminatorFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'type';

    private array $types;

    public function __construct(
        ManagerRegistry $managerRegistry,
        ?LoggerInterface $logger = null,
        ?NameConverterInterface $nameConverter = null,
        ?array $properties = null,
        array $types = [],
    ) {
        parent::__construct($managerRegistry, $logger, $nameConverter, $properties);

        $this->types = $types;
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PROPERTY_NAME => [
                'property' => null,
                'type' => Type::BUILTIN_TYPE_STRING,
                'required' => false,
                'schema' => [
                    'type' => Type::BUILTIN_TYPE_STRING,
                    'enum' => array_keys($this->types),
                ],
                'openapi' => [
                    'name' => self::PROPERTY_NAME,
                    'description' => 'Filter by type',
                    'type' => Type::BUILTIN_TYPE_STRING,
                ],
            ],
        ];
    }

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

        $em = $queryBuilder->getEntityManager();
        $alias = $queryBuilder->getRootAliases()[0];

        if (null !== $value && '' !== $value) {
            $queryBuilder->andWhere("$alias INSTANCE OF :type")
                ->setParameter('type', $em->getClassMetadata($this->types[$value]));
        }
    }
}
