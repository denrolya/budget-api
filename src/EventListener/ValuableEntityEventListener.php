<?php

namespace App\EventListener;

use App\Entity\Transaction;
use App\Entity\ValuableInterface;
use App\Service\AssetsManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Cache\InvalidArgumentException;

final class ValuableEntityEventListener implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    public function __construct(
        private readonly AssetsManager $assetsManager,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        if (!$this->isEnabled()) {
            return;
        }

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
        if (!$this->isEnabled()) {
            return;
        }

        $entity = $args->getObject();
        if ((!$entity instanceof ValuableInterface) || ($entity instanceof Transaction)) {
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
}
