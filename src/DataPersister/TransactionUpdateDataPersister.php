<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use ApiPlatform\Core\DataPersister\ResumableDataPersisterInterface;
use App\Entity\TransactionInterface;
use Doctrine\ORM\EntityManagerInterface;

final class TransactionUpdateDataPersister implements ContextAwareDataPersisterInterface, ResumableDataPersisterInterface
{
    public function __construct(
        private ContextAwareDataPersisterInterface $decorated,
        private EntityManagerInterface             $em
    )
    {
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof TransactionInterface && isset($context['previous_data']);
    }

    /**
     * TODO: Use $context['previous_data'] to calculate changes
     */
    public function persist($data, array $context = [])
    {
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();

        $changes = $uow->getEntityChangeSet($data);

        $isAccountChanged = !empty($changes['account']);
        $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

        if(!$isAccountChanged && !$isAmountChanged) {
            return $this->decorated->persist($data);
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

        return $this->decorated->persist($data);
    }

    public function remove($data, array $context = []): void
    {
        $this->decorated->remove($data);
    }

    public function resumable(array $context = []): bool
    {
        return true;
    }
}
