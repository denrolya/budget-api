<?php

namespace App\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Gedmo\SoftDeleteable\SoftDeleteableListener;
use JetBrains\PhpStorm\ArrayShape;

class WithDeletedFilter extends AbstractFilter
{
    private const PROPERTY_NAME = 'withDeleted';

    /**
     * @inheritDoc
     */
    protected function filterProperty(string $property, $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null)
    {
        if($property !== self::PROPERTY_NAME) {
            return;
        }

        if (filter_var(($value??null), FILTER_VALIDATE_BOOLEAN)) {
            $this->disableSoftDeleteable($queryBuilder->getEntityManager());
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
                'type' => 'boolean',
                'required' => false,
                'openapi' => [
                    'description' => 'This filter toggles the display of soft-deleted elements',
                ],
            ],
        ];
    }

    private function disableSoftDeleteable(EntityManagerInterface $em): void
    {
        foreach($em->getEventManager()->getListeners() as $eventName => $listeners) {
            foreach($listeners as $listener) {
                if($listener instanceof SoftDeleteableListener) {
                    $em->getEventManager()->removeEventListener($eventName, $listener);
                }
            }
        }

        $em->getFilters()->disable('softdeleteable');
    }
}
