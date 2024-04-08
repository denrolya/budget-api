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

        $amount = $transaction->getAmount();
        $oldAmount = $changes['amount'][0] ?? null;
        $newAmount = $changes['amount'][1] ?? null;
        $oldAccount = $changes['account'][0] ?? null;
        $newAccount = $changes['account'][1] ?? null;

        if($isAccountChanged) {
            $oldAccount->updateBalanceBy($transaction->isExpense() ? $amount : -$amount);
            $newAccount->updateBalanceBy($transaction->isIncome() ? $amount : -$amount);
        }

        if($isAmountChanged) {
            $account = $transaction->getAccount();
            $difference = $oldAmount - $newAmount;
            $account->updateBalanceBy($transaction->isExpense() ? $difference : -$difference);
        }
    }
}
