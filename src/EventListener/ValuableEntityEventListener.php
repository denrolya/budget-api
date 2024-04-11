<?php

namespace App\EventListener;

use App\Entity\ExecutableInterface;
use App\Entity\ValuableInterface;
use App\Service\AssetsManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Cache\InvalidArgumentException;

final readonly class ValuableEntityEventListener
{
    public function __construct(
        private AssetsManager $assetsManager,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ValuableInterface) {
            return;
        }

        $this->setConvertedValues($entity);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function setConvertedValues($entity): void
    {
        $entity->setConvertedValues(
            $this->assetsManager->convert($entity)
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ValuableInterface) {
            return;
        }

        if ($entity instanceof ExecutableInterface) {
            $uow = $this->em->getUnitOfWork();
            $uow->computeChangeSets();

            $changes = $uow->getEntityChangeSet($entity);

            $isExecutionDateChanged = !empty($changes['executedAt']);
            $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

            if (!$isExecutionDateChanged && !$isAmountChanged) {
                return;
            }
        }

        // TODO: Check if this is an expense with compensations it's value will be updated in another event, so here it should be skipped

        $this->setConvertedValues($entity);
    }
}
