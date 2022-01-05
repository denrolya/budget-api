<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\DataPersisterInterface;
use App\Entity\Debt;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;

class DebtDataPersister implements DataPersisterInterface
{
    public function __construct(
        private DataPersisterInterface $decoratedDataPersister,
        private EntityManagerInterface $em
    )
    {
    }

    public function supports($data): bool
    {
        return $data instanceof Debt;
    }

    /**
     * @param Debt $data
     */
    public function persist($data)
    {
        /** @var Debt $originalData */
        $originalData = $this->em->getUnitOfWork()->getOriginalEntityData($data);

        if((float)$originalData['balance'] !== $data->getBalance()) {
            $data->setNote(
                $data->getNote() . "\n[" . Carbon::now()->format(CarbonInterface::DEFAULT_TO_STRING_FORMAT) . "][Balance update]: Old balance: " . $originalData['balance']
            );
        }

        return $this->decoratedDataPersister->persist($data);
    }

    public function remove($data): void
    {
        $this->decoratedDataPersister->remove($data);
    }
}
