<?php

declare(strict_types=1);

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

    private UnitOfWork $unitOfWork;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AssetsManager $assetsManager,
    ) {
        $this->unitOfWork = $entityManager->getUnitOfWork();
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
        foreach ($this->unitOfWork->getScheduledEntityInsertions() as $entity) {
            if (!$entity instanceof Transaction) {
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
        foreach ($this->unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Transaction) {
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
        foreach ($this->unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof Transaction) {
                continue;
            }

            $this->updateAccountBalanceOnRemove($entity);

            if ($entity->getDebt()) {
                $this->updateDebtBalanceOnRemove($entity);
            }
        }
    }

    private function areValuableFieldsUpdated(Transaction $transaction): bool
    {
        $changeSet = $this->unitOfWork->getEntityChangeSet($transaction);

        $isExecutionDateChanged = isset($changeSet['executedAt']);
        $isAmountChanged = isset($changeSet['amount'])
            && ($this->changeSetFloat($changeSet['amount'], 0) !== $this->changeSetFloat($changeSet['amount'], 1));
        $isAccountChanged = isset($changeSet['account']);

        return $isExecutionDateChanged || $isAmountChanged || $isAccountChanged;
    }

    private function recalculateConvertedValues(Transaction $transaction): void
    {
        $convertedValues = $this->assetsManager->convert($transaction);
        $transaction->setConvertedValues($convertedValues);

        if ([] !== $this->unitOfWork->getEntityChangeSet($transaction)) {
            $this->unitOfWork->recomputeSingleEntityChangeSet(
                $this->entityManager->getClassMetadata($transaction::class),
                $transaction,
            );
        }
    }

    private function updateAccountBalanceOnPersist(Transaction $transaction): void
    {
        $account = $transaction->getAccount();
        $account->increaseBalance($transaction->isExpense() ? -$transaction->getAmount() : $transaction->getAmount());
        $this->unitOfWork->recomputeSingleEntityChangeSet(
            $this->entityManager->getClassMetadata(Account::class),
            $account,
        );
    }

    private function updateAccountBalanceOnUpdate(Transaction $transaction): void
    {
        $changeSet = $this->unitOfWork->getEntityChangeSet($transaction);

        $isAccountChanged = isset($changeSet['account']);
        $isAmountChanged = isset($changeSet['amount'])
            && ($this->changeSetFloat($changeSet['amount'], 0) !== $this->changeSetFloat($changeSet['amount'], 1));

        if ($isAccountChanged) {
            [$oldAccount, $newAccount] = $this->changeSetAccounts($changeSet['account']);

            if ($isAmountChanged) {
                $oldAmount = $this->changeSetFloat($changeSet['amount'], 0);
                $newAmount = $this->changeSetFloat($changeSet['amount'], 1);
                $oldAccount->updateBalanceBy($transaction->isExpense() ? $oldAmount : -$oldAmount);
                $newAccount->updateBalanceBy($transaction->isIncome() ? $newAmount : -$newAmount);
            } else {
                $amount = $transaction->getAmount();
                $oldAccount->updateBalanceBy($transaction->isExpense() ? $amount : -$amount);
                $newAccount->updateBalanceBy($transaction->isIncome() ? $amount : -$amount);
            }

            $this->unitOfWork->recomputeSingleEntityChangeSet($this->entityManager->getClassMetadata(Account::class), $oldAccount);
            $this->unitOfWork->recomputeSingleEntityChangeSet($this->entityManager->getClassMetadata(Account::class), $newAccount);
        } elseif ($isAmountChanged) {
            $oldAmount = $this->changeSetFloat($changeSet['amount'], 0);
            $newAmount = $this->changeSetFloat($changeSet['amount'], 1);
            $difference = $oldAmount - $newAmount;
            $transaction->getAccount()->updateBalanceBy($transaction->isExpense() ? $difference : -$difference);
            $this->unitOfWork->recomputeSingleEntityChangeSet(
                $this->entityManager->getClassMetadata(Account::class),
                $transaction->getAccount(),
            );
        }
    }

    private function updateAccountBalanceOnRemove(Transaction $transaction): void
    {
        $account = $transaction->getAccount();
        $account->decreaseBalance($transaction->isExpense() ? -$transaction->getAmount() : $transaction->getAmount());
        $this->unitOfWork->recomputeSingleEntityChangeSet(
            $this->entityManager->getClassMetadata(Account::class),
            $account,
        );
    }

    private function updateDebtBalanceOnPersist(Transaction $transaction): void
    {
        $debt = $transaction->getDebt();
        \assert(null !== $debt);
        $amount = $transaction->getConvertedValue($debt->getCurrency());

        $debtValue = $debt->getConvertedValues();
        foreach (array_keys($transaction->getConvertedValues()) as $currency) {
            $debtValue[$currency] = $transaction->isExpense()
                ? ($debtValue[$currency] ?? 0.0) + $transaction->getConvertedValue($currency)
                : ($debtValue[$currency] ?? 0.0) - $transaction->getConvertedValue($currency);
        }
        $debt
            ->increaseBalance($transaction->isExpense() ? $amount : -$amount)
            ->setConvertedValues($debtValue);
        $this->unitOfWork->recomputeSingleEntityChangeSet(
            $this->entityManager->getClassMetadata(Debt::class),
            $debt,
        );
    }

    private function updateDebtBalanceOnUpdate(Transaction $transaction): void
    {
        $debt = $transaction->getDebt();
        \assert(null !== $debt);
        $debtCurrency = $debt->getCurrency();
        $changeSet = $this->unitOfWork->getEntityChangeSet($transaction);

        $isAccountChanged = isset($changeSet['account']);
        $isAmountChanged = isset($changeSet['amount'])
            && ($this->changeSetFloat($changeSet['amount'], 0) !== $this->changeSetFloat($changeSet['amount'], 1));

        if (!isset($changeSet['convertedValues'])) {
            return; // amount/account/date unchanged — converted value did not change, no debt adjustment needed
        }

        if ($isAccountChanged || $isAmountChanged) {
            [$oldConvertedValues, $newConvertedValues] = $this->changeSetConvertedValues($changeSet['convertedValues']);
            $debt->decreaseBalance(
                $transaction->isExpense()
                    ? $oldConvertedValues[$debtCurrency]
                    : -$oldConvertedValues[$debtCurrency],
            );
            $debt->increaseBalance(
                $transaction->isExpense()
                    ? $newConvertedValues[$debtCurrency]
                    : -$newConvertedValues[$debtCurrency],
            );

            $this->unitOfWork->recomputeSingleEntityChangeSet(
                $this->entityManager->getClassMetadata(Debt::class),
                $debt,
            );
        }
    }

    private function updateDebtBalanceOnRemove(Transaction $transaction): void
    {
        $debt = $transaction->getDebt();
        \assert(null !== $debt);
        $amount = $transaction->getConvertedValue($debt->getCurrency());

        $debtValue = $debt->getConvertedValues();
        foreach (array_keys($transaction->getConvertedValues()) as $currency) {
            $debtValue[$currency] = $transaction->isExpense()
                ? ($debtValue[$currency] ?? 0.0) - $transaction->getConvertedValue($currency)
                : ($debtValue[$currency] ?? 0.0) + $transaction->getConvertedValue($currency);
        }
        $debt
            ->decreaseBalance($transaction->isExpense() ? $amount : -$amount)
            ->setConvertedValues($debtValue);
        $this->unitOfWork->recomputeSingleEntityChangeSet(
            $this->entityManager->getClassMetadata(Debt::class),
            $debt,
        );
    }

    /**
     * Extracts a float value from a Doctrine changeset tuple.
     *
     * @param array{0: mixed, 1: mixed}|\Doctrine\ORM\PersistentCollection<int, mixed> $changeSetPair
     */
    private function changeSetFloat(array|\Doctrine\ORM\PersistentCollection $changeSetPair, int $index): float
    {
        $value = $changeSetPair[$index];
        \assert(is_numeric($value));

        return (float) $value;
    }

    /**
     * Extracts the old/new Account pair from a Doctrine changeset.
     *
     * @param array{0: mixed, 1: mixed}|\Doctrine\ORM\PersistentCollection<int, mixed> $changeSetPair
     *
     * @return array{0: Account, 1: Account}
     */
    private function changeSetAccounts(array|\Doctrine\ORM\PersistentCollection $changeSetPair): array
    {
        $oldAccount = $changeSetPair[0];
        $newAccount = $changeSetPair[1];
        \assert($oldAccount instanceof Account);
        \assert($newAccount instanceof Account);

        return [$oldAccount, $newAccount];
    }

    /**
     * Extracts the old/new converted values from a Doctrine changeset.
     *
     * @param array{0: mixed, 1: mixed}|\Doctrine\ORM\PersistentCollection<int, mixed> $changeSetPair
     *
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    private function changeSetConvertedValues(array|\Doctrine\ORM\PersistentCollection $changeSetPair): array
    {
        $oldValues = $changeSetPair[0];
        $newValues = $changeSetPair[1];
        \assert(\is_array($oldValues));
        \assert(\is_array($newValues));

        return [$oldValues, $newValues];
    }
}
