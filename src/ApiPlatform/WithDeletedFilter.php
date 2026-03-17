<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

class WithDeletedFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'withDeleted';

    public function getDescription(string $resourceClass): array
    {
        return [
            self::PROPERTY_NAME => [
                'property' => null,
                'type' => 'boolean',
                'required' => false,
                'openapi' => [
                    'description' => 'This filter toggles the display of soft-deleted elements',
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

        if (filter_var($value ?? null, \FILTER_VALIDATE_BOOLEAN)) {
            $this->disableSoftDeleteable($queryBuilder->getEntityManager());
        }
    }

    private function disableSoftDeleteable(EntityManagerInterface $em): void
    {
        // Disabling the Doctrine filter is sufficient to include soft-deleted records.
        // The event listener is kept so that deletedAt is still set on new soft-deletes.
        $em->getFilters()->disable('softdeleteable');
    }
}
