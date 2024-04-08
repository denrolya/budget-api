<?php

namespace App\EventListener;

use App\Entity\ExecutableInterface;
use App\Entity\ValuableInterface;
use App\Service\FixerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;

final class ValuableEntityEventListener
{
    public function __construct(
        private FixerService           $fixer,
        private EntityManagerInterface $em,
    )
    {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if(!$entity instanceof ValuableInterface) {
            return;
        }

        $this->setConvertedValues($entity);
    }

    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if(!$entity instanceof ValuableInterface) {
            return;
        }

        if($entity instanceof ExecutableInterface) {
            $uow = $this->em->getUnitOfWork();
            $uow->computeChangeSets();

            $changes = $uow->getEntityChangeSet($entity);

            $isExecutionDateChanged = !empty($changes['executedAt']);
            $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

            if(!$isExecutionDateChanged || !$isAmountChanged) {
                return;
            }
        }

        $this->setConvertedValues($entity);
    }

    private function setConvertedValues($entity): void
    {
        $values = $this->fixer->convert(
            $entity->{'get' . ucfirst($entity->getValuableField())}(),
            $entity->getCurrency(),
            $entity instanceof ExecutableInterface ? $entity->getExecutedAt() : null
        );

        $entity->setConvertedValues($values);
    }
}
