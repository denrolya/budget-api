<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use App\Entity\TransactionInterface;
use Doctrine\ORM\EntityManagerInterface;

final class TransactionUpdateDataPersister implements ContextAwareDataPersisterInterface
{
    public function __construct(
        private ContextAwareDataPersisterInterface $decoratedDataPersister,
        private EntityManagerInterface             $em
    )
    {
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof TransactionInterface
            && array_key_exists('item_operation_name', $context)
            && $context['item_operation_name'] === 'put';
    }

    public function persist($data, array $context = [])
    {
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();

        $changes = $uow->getEntityChangeSet($data);

        $isAccountChanged = !empty($changes['account']);
        $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

        if(!$isAccountChanged && !$isAmountChanged) {
            return;
        }

        if($isAccountChanged && !$isAmountChanged) {
            $amount = $data->getAmount();
            [$oldAccount, $newAccount] = $changes['account'];

            $oldAccount->updateBalanceBy($data->isExpense() ? $amount : -$amount);
            $newAccount->updateBalanceBy($data->isIncome() ? $amount : -$amount);
        } elseif(!$isAccountChanged && $isAmountChanged) {
            $account = $data->getAccount();
            [$oldAmount, $newAmount] = $changes['amount'];
            $difference = $oldAmount - $newAmount;

            $account->updateBalanceBy($data->isExpense() ? $difference : -$difference);
        } elseif($isAccountChanged && $isAmountChanged) {
            [$oldAccount, $newAccount] = $changes['account'];
            [$oldAmount, $newAmount] = $changes['amount'];

            $oldAccount->updateBalanceBy($data->isExpense() ? $oldAmount : -$oldAmount);
            $newAccount->updateBalanceBy($data->isExpense() ? -$newAmount : $newAmount);
        }

        // Don't flush cause AccountLogger will do it afterwards

        $this->decoratedDataPersister->persist($data);
    }

    public function remove($data, array $context = []): void
    {
        $this->decoratedDataPersister->remove($data);
    }
}
