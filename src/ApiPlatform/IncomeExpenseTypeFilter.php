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
 * TODO: Rename to 'DiscriminatorFilter' and accept only one value instead of array
 */
final class IncomeExpenseTypeFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'types';

    private array $types;

    public function __construct(ManagerRegistry $managerRegistry, ?RequestStack $requestStack = null, LoggerInterface $logger = null, array $properties = null, NameConverterInterface $nameConverter = null, array $types = [])
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

        if(!empty($value) && count($value) === 1) {
            $queryBuilder->andWhere("$alias INSTANCE OF :type")
                ->setParameter('type', $em->getClassMetadata($this->types[$value[0]]));
        }
    }

    /**
     * @inheritDoc
     */
    #[ArrayShape([self::PROPERTY_NAME => "array"])]
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
                        'type' => Type::BUILTIN_TYPE_STRING,
                    ],
                ],
                'openapi' => [
                    'name' => self::PROPERTY_NAME,
                    'description' => 'This filter toggles the display of soft-deleted elements',
                    'type' => Type::BUILTIN_TYPE_ARRAY,
                ],
            ],
        ];
    }
}
