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

        /**
         *  TODO: This is necessary cause now amount for transactions is stored as double and some weird conversion
         *      from decimal to string and to back to float is happening; Solution will be to store not in decimal but in
         *      minimal item(cents, forints, etc...)
         */
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
