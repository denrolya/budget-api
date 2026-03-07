<?php

namespace App\Traits;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\Attribute\Required;

trait SoftDeletableTogglerController
{
    private EntityManagerInterface $entityManager;

    #[Required]
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    private function disableSoftDeletable(): void
    {
        // Disabling the Doctrine filter is sufficient to include soft-deleted
        // records in queries. The event listener is kept so that deletedAt is
        // still set correctly when new soft-deletes happen in the same request.
        $this->entityManager->getFilters()->disable('softdeleteable');
    }
}
