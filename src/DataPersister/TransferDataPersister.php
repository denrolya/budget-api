<?php

declare(strict_types=1);

namespace App\DataPersister;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Account;
use App\Entity\Transfer;
use App\Service\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class TransferDataPersister implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransferService $transferService,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof Transfer) {
            return $data;
        }

        $fees = $this->extractFees();
        $isCreate = !isset($context['previous_data']);

        if ($isCreate) {
            $data->setOwner($this->security->getUser());
            $this->transferService->createTransactions($data, $fees);
        } else {
            $this->transferService->updateTransactions($data, $fees);
        }

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }

    /**
     * @return array<array{amount: string, account: Account}>
     */
    private function extractFees(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return [];
        }

        $rawFees = $payload['fees'] ?? [];

        $fees = [];
        foreach ($rawFees as $rawFee) {
            $amount = $rawFee['amount'] ?? null;
            $accountId = $rawFee['account'] ?? null;

            if ($amount === null || $accountId === null) {
                continue;
            }

            if (!is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $account = $this->entityManager->getRepository(Account::class)->find($accountId);
            if ($account === null) {
                continue;
            }

            $fees[] = [
                'amount' => (string) $amount,
                'account' => $account,
            ];
        }

        return $fees;
    }
}
