<?php

declare(strict_types=1);

namespace App\DataPersister;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Transfer;
use App\Service\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class TransferDataPersister implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransferService $transferService,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Transfer) {
            return $data;
        }

        $isCreate = !isset($context['previous_data']);

        if ($isCreate) {
            $owner = $this->security->getUser();
            \assert(null !== $owner);
            $data->setOwner($owner);
            $this->transferService->createTransactions($data);
        } else {
            $this->transferService->updateTransactions($data);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
