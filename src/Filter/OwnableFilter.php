<?php

namespace App\Filter;

use App\Entity\OwnableInterface;
use Doctrine\ORM\Mapping\ClassMetaData;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Symfony\Component\Security\Core\Security;

class OwnableFilter extends SQLFilter
{
    protected Security $security;

    public function setSecurity(Security $security): void
    {
        $this->security = $security;
    }

    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if(!$targetEntity->reflClass->implementsInterface(OwnableInterface::class)) {
            return '';
        }

        return "{$targetTableAlias}.owner_id = {$this->getParameter('currentUserId')}";
    }
}
