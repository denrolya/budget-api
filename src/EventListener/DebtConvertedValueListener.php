<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Debt;
use App\Service\AssetsManager;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Psr\Cache\InvalidArgumentException;

final class DebtConvertedValueListener implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    public function __construct(
        private readonly AssetsManager $assetsManager,
    ) {
    }

    /** @throws InvalidArgumentException */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->handleEvent($args);
    }

    /** @throws InvalidArgumentException */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $this->handleEvent($args);
    }

    /** @throws InvalidArgumentException */
    private function handleEvent(LifecycleEventArgs $args): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $entity = $args->getObject();
        if (!$entity instanceof Debt) {
            return;
        }

        $entity->setConvertedValues($this->assetsManager->convert($entity));
    }
}
