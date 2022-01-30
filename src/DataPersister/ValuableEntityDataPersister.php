<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\DataPersisterInterface;
use ApiPlatform\Core\DataPersister\ResumableDataPersisterInterface;
use App\Entity\ExecutableInterface;
use App\Entity\ValuableInterface;
use App\Service\FixerService;

final class ValuableEntityDataPersister implements DataPersisterInterface, ResumableDataPersisterInterface
{
    public function __construct(
        private DataPersisterInterface $decorated,
        private FixerService           $fixer
    )
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

        $data->setConvertedValues($values);

        return $this->decorated->persist($data);
    }

    public function remove($data): void
    {
        $this->decorated->remove($data);
    }

    public function resumable(array $context = []): bool
    {
        return true;
    }
}
