<?php

namespace App\EventListener;

use App\Entity\TransactionInterface;
use Doctrine\ORM\EntityManagerInterface;

final class TransactionUpdateHandler implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function postUpdate(TransactionInterface $transaction): void
    {
        if(!$this->enabled) {
            return;
        }

        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();

        $changes = $uow->getEntityChangeSet($transaction);

        $isAccountChanged = !empty($changes['account']);
        $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

        if(!$isAccountChanged && !$isAmountChanged) {
            return;
        }

        if($isAccountChanged && !$isAmountChanged) {
            $amount = $transaction->getAmount();
            [$oldAccount, $newAccount] = $changes['account'];

            $oldAccount->updateBalanceBy($transaction->isExpense() ? $amount : -$amount);
            $newAccount->updateBalanceBy($transaction->isIncome() ? $amount : -$amount);
        } elseif(!$isAccountChanged && $isAmountChanged) {
            $account = $transaction->getAccount();
            [$oldAmount, $newAmount] = $changes['amount'];
            $difference = $oldAmount - $newAmount;

            $account->updateBalanceBy($transaction->isExpense() ? $difference : -$difference);
        } elseif($isAccountChanged && $isAmountChanged) {
            [$oldAccount, $newAccount] = $changes['account'];
            [$oldAmount, $newAmount] = $changes['amount'];

            $oldAccount->updateBalanceBy($transaction->isExpense() ? $oldAmount : -$oldAmount);
            $newAccount->updateBalanceBy($transaction->isExpense() ? -$newAmount : $newAmount);
        }
    }
}
