<?php

declare(strict_types=1);

namespace App\Filter;

use App\Entity\OwnableInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

class OwnableFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if (!is_a($targetEntity->name, OwnableInterface::class, true)) {
            return '';
        }

        return "{$targetTableAlias}.owner_id = {$this->getParameter('currentUserId')}";
    }
}
