<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;

final class DebtTransactionDataPersister implements ContextAwareDataPersisterInterface
{
    public function __construct(
        private ContextAwareDataPersisterInterface $decoratedDataPersister,
        private AssetsManager                      $assetsManager,
    )
    {
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof TransactionInterface && $data->getDebt() !== null;
    }

    /**
     * @param TransactionInterface $data
     * @param array $context
     * @return void
     */
    public function persist($data, array $context = []): void
    {
        $debt = $data->getDebt();
        $amount = $this->assetsManager->convert($data)[$debt->getCurrency()];
        $debt->decreaseBalance(($data->isExpense() ? -1 * $amount : $amount));
        $this->decoratedDataPersister->persist($data);
    }

    /**
     * @param TransactionInterface $data
     * @param array $context
     * @return void
     */
    public function remove($data, array $context = []): void
    {
        $debt = $data->getDebt();
        $amount = $this->assetsManager->convert($data)[$debt->getCurrency()];
        $debt->increaseBalance(($data->isExpense() ? -1 * $amount : $amount));
        $this->decoratedDataPersister->remove($data);
    }
}
