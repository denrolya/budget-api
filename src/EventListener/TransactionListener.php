<?php

namespace App\EventListener;

use App\Entity\Account;
use App\Entity\Debt;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Psr\Cache\InvalidArgumentException;

final class TransactionListener extends BaseUpdateTransactionValueHandler implements ToggleEnabledInterface
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->handleInsertions();

        $this->handleUpdates();

        $this->handleDeletions();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function handleInsertions(): void
    {
        foreach ($this->uow->getScheduledEntityInsertions() as $entity) {
            if (!is_a($entity, Transaction::class)) {
                continue;
            }

            if ($entity instanceof Expense) {
                $this->recalculateExpenseWithCompensationsValue($entity);
            } elseif ($entity instanceof Income) {
                if ($entity->getOriginalExpense()) {
                    $this->recalculateExpenseWithCompensationsValue($entity->getOriginalExpense());
                } else {
                    $this->recalculateIncomeValue($entity);
                }
            }

            $this->updateAccountBalanceOnPersist($entity);

            if ($entity->getDebt()) {
                $this->updateDebtBalanceOnPersist($entity);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function handleUpdates(): void
    {
        foreach ($this->uow->getScheduledEntityUpdates() as $entity) {
            if (!is_a($entity, Transaction::class)) {
                continue;
            }

            if ($entity instanceof Expense && $this->areValuableFieldsUpdated($entity)) {
                $this->recalculateExpenseWithCompensationsValue($entity);
            } elseif ($entity instanceof Income && $this->areValuableFieldsUpdated($entity)) {
                if ($entity->getOriginalExpense()) {
                    $this->recalculateExpenseWithCompensationsValue($entity->getOriginalExpense());
                } else {
                    $this->recalculateIncomeValue($entity);
                }
            }

            $this->updateAccountBalanceOnUpdate($entity);

            if ($entity->getDebt()) {
                $this->updateDebtBalanceOnUpdate($entity);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function handleDeletions(): void
    {
        foreach ($this->uow->getScheduledEntityDeletions() as $entity) {
            if (!is_a($entity, Transaction::class)) {
                continue;
            }

            if ($entity instanceof Income && $entity->getOriginalExpense()) {
                $this->recalculateExpenseWithCompensationsValue($entity->getOriginalExpense(), $entity->getId());
            }

            $this->updateAccountBalanceOnRemove($entity);

            if ($entity->getDebt()) {
                $this->updateDebtBalanceOnRemove($entity);
            }
        }
    }

    private function updateAccountBalanceOnPersist(Transaction $transaction): void
    {
        $account = $transaction->getAccount();
        $account->increaseBalance($transaction->isExpense() ? -$transaction->getAmount() : $transaction->getAmount());
        $this->uow->recomputeSingleEntityChangeSet(
            $this->em->getClassMetadata(Account::class),
            $account
        );
    }

    private function updateAccountBalanceOnUpdate(Transaction $transaction): void
    {
        $changeSet = $this->uow->getEntityChangeSet($transaction);

        $isAccountChanged = !empty($changeSet['account']);
        $isAmountChanged = (!empty($changeSet['amount']) && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]));

        if ($isAccountChanged) {
            [$oldAccount, $newAccount] = $changeSet['account'];

            if ($isAmountChanged) {
                [$oldAmount, $newAmount] = $changeSet['amount'];
                $oldAccount->updateBalanceBy($transaction->isExpense() ? $oldAmount : -$oldAmount);
                $newAccount->updateBalanceBy($transaction->isIncome() ? $newAmount : -$newAmount);
            } else {
                $amount = $transaction->getAmount();
                $oldAccount->updateBalanceBy($transaction->isExpense() ? $amount : -$amount);
                $newAccount->updateBalanceBy($transaction->isIncome() ? $amount : -$amount);
            }

            $this->uow->recomputeSingleEntityChangeSet($this->em->getClassMetadata(Account::class), $oldAccount);
            $this->uow->recomputeSingleEntityChangeSet($this->em->getClassMetadata(Account::class), $newAccount);
        } elseif ($isAmountChanged) {
            [$oldAmount, $newAmount] = $changeSet['amount'];
            $difference = $oldAmount - $newAmount;
            $transaction->getAccount()->updateBalanceBy($transaction->isExpense() ? $difference : -$difference);
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(Account::class),
                $transaction->getAccount()
            );
        }
    }

    private function updateAccountBalanceOnRemove(Transaction $transaction): void
    {
        $account = $transaction->getAccount();
        $account->decreaseBalance($transaction->isExpense() ? -$transaction->getAmount() : $transaction->getAmount());
        $this->uow->recomputeSingleEntityChangeSet(
            $this->em->getClassMetadata(Account::class),
            $account
        );
    }

    private function updateDebtBalanceOnPersist(Transaction $transaction): void
    {
        $debt = $transaction->getDebt();
        $amount = $transaction->getConvertedValue($debt->getCurrency());

        $debtValue = $debt->getConvertedValues();
        foreach (array_keys($transaction->getConvertedValues()) as $currency) {
            $debtValue[$currency] = $transaction->isExpense()
                ? $debtValue[$currency] + $transaction->getConvertedValue($currency)
                : $debtValue[$currency] - $transaction->getConvertedValue($currency);
        }
        $debt
            ->increaseBalance(($transaction->isExpense() ? $amount : -$amount))
            ->setConvertedValues($debtValue);
        $this->uow->recomputeSingleEntityChangeSet(
            $this->em->getClassMetadata(Debt::class),
            $debt
        );
    }

    private function updateDebtBalanceOnUpdate(Transaction $transaction): void
    {
        $debt = $transaction->getDebt();
        $debtCurrency = $debt->getCurrency();
        $changeSet = $this->uow->getEntityChangeSet($transaction);

        $isAccountChanged = !empty($changeSet['account']);
        $isAmountChanged = (!empty($changeSet['amount']) && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]));

        if (!isset($changeSet['convertedValues'])) {
            throw new \RuntimeException('Converted values are not set in change set');
        }

        if ($isAccountChanged || $isAmountChanged) {
            [$oldConvertedValues, $newConvertedValues] = $changeSet['convertedValues'];
            $debt->decreaseBalance(
                $transaction->isExpense()
                    ? $oldConvertedValues[$debtCurrency]
                    : -$oldConvertedValues[$debtCurrency]
            );
            $debt->increaseBalance(
                $transaction->isExpense()
                    ? $newConvertedValues[$debtCurrency]
                    : -$newConvertedValues[$debtCurrency]
            );

            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(Debt::class),
                $debt
            );
        }
    }

    private function updateDebtBalanceOnRemove(Transaction $transaction): void
    {
        $debt = $transaction->getDebt();
        $amount = $transaction->getConvertedValue($debt->getCurrency());

        $debtValue = $debt->getConvertedValues();
        foreach (array_keys($transaction->getConvertedValues()) as $currency) {
            $debtValue[$currency] = $transaction->isExpense()
                ? $debtValue[$currency] - $transaction->getConvertedValue($currency)
                : $debtValue[$currency] + $transaction->getConvertedValue($currency);
        }
        $debt
            ->decreaseBalance(($transaction->isExpense() ? $amount : -$amount))
            ->setConvertedValues($debtValue);
        $this->uow->recomputeSingleEntityChangeSet(
            $this->em->getClassMetadata(Debt::class),
            $debt
        );
    }
}
