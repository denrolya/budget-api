<?php

declare(strict_types=1);

namespace App\DataPersister;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Debt;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DebtDataPersister implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Debt) {
            return $data;
        }

        if (isset($context['previous_data'])) {
            $originalData = $this->em->getUnitOfWork()->getOriginalEntityData($data);

            $originalBalance = $originalData['balance'] ?? null;
            assert(null === $originalBalance || is_numeric($originalBalance));
            if ((float) $originalBalance !== $data->getBalance()) {
                $data->setNote(
                    $data->getNote() . "\n[" . Carbon::now()->format(CarbonInterface::DEFAULT_TO_STRING_FORMAT) . '][Balance update]: Old balance: ' . $originalBalance,
                );
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
