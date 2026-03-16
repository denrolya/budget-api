<?php

namespace App\EventListener;

use App\Entity\Account;
use App\Entity\Debt;
use App\Entity\Transaction;
use App\Service\AssetsManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Psr\Cache\InvalidArgumentException;

final class TransactionListener implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    private UnitOfWork $uow;

    public function __construct(
        private EntityManagerInterface $em,
        private AssetsManager $assetsManager,
    ) {
        $this->uow = $em->getUnitOfWork();
    }

    /**
     * @throws InvalidArgumentException
     */
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
            if (!($entity instanceof Transaction)) {
                continue;
            }

            $this->recalculateConvertedValues($entity);
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
            if (!($entity instanceof Transaction)) {
                continue;
            }

            if ($this->areValuableFieldsUpdated($entity)) {
                $this->recalculateConvertedValues($entity);
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
            if (!($entity instanceof Transaction)) {
                continue;
            }

            $this->updateAccountBalanceOnRemove($entity);

            if ($entity->getDebt()) {
                $this->updateDebtBalanceOnRemove($entity);
            }
        }
    }

    private function areValuableFieldsUpdated($transaction): bool
    {
        $changeSet = $this->uow->getEntityChangeSet($transaction);

        $isExecutionDateChanged = isset($changeSet['executedAt']);
        $isAmountChanged = isset($changeSet['amount'])
            && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]);
        $isAccountChanged = isset($changeSet['account']);

        return $isExecutionDateChanged || $isAmountChanged || $isAccountChanged;
    }

    private function recalculateConvertedValues(Transaction $transaction): void
    {
        $convertedValues = $this->assetsManager->convert($transaction);
        $transaction->setConvertedValues($convertedValues);

        if ($this->uow->getEntityChangeSet($transaction) !== []) {
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(get_class($transaction)),
                $transaction
            );
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

        $isAccountChanged = isset($changeSet['account']);
        $isAmountChanged = (isset($changeSet['amount']) && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]));

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
                ? ($debtValue[$currency] ?? 0.0) + $transaction->getConvertedValue($currency)
                : ($debtValue[$currency] ?? 0.0) - $transaction->getConvertedValue($currency);
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

        $isAccountChanged = isset($changeSet['account']);
        $isAmountChanged = (isset($changeSet['amount']) && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]));

        if (!isset($changeSet['convertedValues'])) {
            return; // amount/account/date unchanged — converted value did not change, no debt adjustment needed
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
                ? ($debtValue[$currency] ?? 0.0) - $transaction->getConvertedValue($currency)
                : ($debtValue[$currency] ?? 0.0) + $transaction->getConvertedValue($currency);
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
