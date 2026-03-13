<?php

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /** @return PushSubscription[] */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /** @return PushSubscription[] — all subscriptions across all users (for broadcast notifications). */
    public function findAll(): array
    {
        return parent::findAll();
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    public function removeByEndpoint(string $endpoint): void
    {
        $sub = $this->findByEndpoint($endpoint);
        if ($sub === null) {
            return;
        }

        $this->getEntityManager()->remove($sub);
        $this->getEntityManager()->flush();
    }
}
