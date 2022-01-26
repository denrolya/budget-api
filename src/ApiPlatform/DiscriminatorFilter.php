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

final class DiscriminatorFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'type';

    private array $types;

    public function __construct(
        ManagerRegistry        $managerRegistry,
        ?RequestStack          $requestStack = null,
        LoggerInterface        $logger = null,
        array                  $properties = null,
        NameConverterInterface $nameConverter = null,
        array                  $types = []
    )
    {
        parent::__construct($managerRegistry, $requestStack, $logger, $properties, $nameConverter);

        $this->types = $types;
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
        string                      $operationName = null
    ): void
    {
        if($property !== self::PROPERTY_NAME) {
            return;
        }

        $em = $queryBuilder->getEntityManager();
        $alias = $queryBuilder->getRootAliases()[0];

        if(!empty($value)) {
            $queryBuilder->andWhere("$alias INSTANCE OF :type")
                ->setParameter('type', $em->getClassMetadata($this->types[$value]));
        }
    }

    /**
     * @inheritDoc
     */
    #[ArrayShape([self::PROPERTY_NAME => "array"])]
    public function getDescription(string $resourceClass): array
    {
        return [
            self::PROPERTY_NAME => [
                'property' => null,
                'type' => Type::BUILTIN_TYPE_STRING,
                'required' => false,
                'schema' => [
                    'type' => Type::BUILTIN_TYPE_STRING,
                    'enum' => array_keys($this->types)
                ],
                'openapi' => [
                    'name' => self::PROPERTY_NAME,
                    'description' => 'Filter by type',
                    'type' => Type::BUILTIN_TYPE_STRING,
                ],
            ],
        ];
    }
}
