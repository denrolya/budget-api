<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\DataPersisterInterface;
use ApiPlatform\Core\DataPersister\ResumableDataPersisterInterface;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use Psr\Cache\InvalidArgumentException;

final class DebtTransactionDataPersister implements DataPersisterInterface, ResumableDataPersisterInterface
{
    public function __construct(
        private DataPersisterInterface $decorated,
        private AssetsManager          $assetsManager,
    )
    {
    }

    public function supports($data): bool
    {
        return $data instanceof TransactionInterface && $data->getDebt() !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function persist($data)
    {
        $debt = $data->getDebt();
        $amount = $this->assetsManager->convert($data)[$debt->getCurrency()];
        $debt->decreaseBalance(($data->isExpense() ? -1 * $amount : $amount));

        return $this->decorated->persist($data);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function remove($data): void
    {
        $debt = $data->getDebt();
        $amount = $this->assetsManager->convert($data)[$debt->getCurrency()];
        $debt->increaseBalance(($data->isExpense() ? -1 * $amount : $amount));
        $this->decorated->remove($data);
    }

    public function resumable(array $context = []): bool
    {
        return true;
    }
}
