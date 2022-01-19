<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\DataPersisterInterface;
use App\Entity\ExecutableInterface;
use App\Entity\ValuableInterface;
use App\Service\FixerService;

final class ValuableEntityDataPersister implements DataPersisterInterface
{
    public function __construct(private FixerService $fixer, private DataPersisterInterface $decoratedDataPersister)
    {
    }

    public function supports($data): bool
    {
        return $data instanceof ValuableInterface;
    }

    public function persist($data)
    {
        $this->setConvertedValues($data);

        return $this->decoratedDataPersister->persist($data);
    }

    public function remove($data): void
    {
        $this->decoratedDataPersister->remove($data);
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
