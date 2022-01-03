<?php

namespace App\Filter;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class Configurator
{
    protected EntityManagerInterface $em;

    protected Security $security;

    public function __construct(EntityManagerInterface $em, Security $security)
    {
        $this->em = $em;
        $this->security = $security;
    }

    public function onKernelRequest(): void
    {
        if($currentUser = $this->security->getUser()) {
            $filter = $this->em->getFilters()->enable('ownable');
            $filter->setParameter('currentUserId', $currentUser->getId());
        }
    }
}
