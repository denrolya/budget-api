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
        $values = $this->fixer->convert(
            $data->{'get' . ucfirst($data->getValuableField())}(),
            $data->getCurrency(),
            $data instanceof ExecutableInterface ? $data->getExecutedAt() : null
        );

        dump($values, $data);
        $data->setConvertedValues($values);

        return $this->decoratedDataPersister->persist($data);
    }

    public function remove($data): void
    {
        $this->decoratedDataPersister->remove($data);
    }
}
