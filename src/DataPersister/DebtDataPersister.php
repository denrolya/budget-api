<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use App\Entity\Debt;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DebtDataPersister implements ContextAwareDataPersisterInterface
{
    public function __construct(
        private ContextAwareDataPersisterInterface $decorated,
        private EntityManagerInterface             $em
    )
    {
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof Debt && isset($context['previous_data']);
    }

    /**
     * @param Debt $data
     */
    public function persist($data, array $context = [])
    {
        /** @var Debt $originalData */
        $originalData = $this->em->getUnitOfWork()->getOriginalEntityData($data);

        if((float)$originalData['balance'] !== $data->getBalance()) {
            $data->setNote(
                $data->getNote() . "\n[" . Carbon::now()->format(CarbonInterface::DEFAULT_TO_STRING_FORMAT) . "][Balance update]: Old balance: " . $originalData['balance']
            );
        }

        return $this->decorated->persist($data);
    }

    public function remove($data, array $context = []): void
    {
        $this->decorated->remove($data);
    }
}
