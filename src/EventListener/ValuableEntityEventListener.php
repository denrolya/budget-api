<?php

namespace App\EventListener;

use App\Entity\ExecutableInterface;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
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
        if ((!$entity instanceof ValuableInterface) || ($entity instanceof Transaction)) {
            return;
        }

        $this->setConvertedValues($entity);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();
        if ((!$entity instanceof ValuableInterface) || ($entity instanceof Transaction)) {
            return;
        }

        if ($entity instanceof ExecutableInterface) {
            $uow = $this->em->getUnitOfWork();
            $uow->computeChangeSets();

            $changes = $uow->getEntityChangeSet($entity);

            $isExecutionDateChanged = !empty($changes['executedAt']);
            $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);
            $isAccountChanged = !empty($changes['account']);

            if (!$isExecutionDateChanged && !$isAmountChanged && !$isAccountChanged) {
                return;
            }
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
}
