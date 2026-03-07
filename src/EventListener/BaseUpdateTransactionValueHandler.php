<?php

namespace App\EventListener;

use App\Entity\Expense;
use App\Entity\Income;
use App\Service\AssetsManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;

abstract class BaseUpdateTransactionValueHandler
{
    use ToggleEnabledTrait;

    protected UnitOfWork $uow;

    public function __construct(
        protected EntityManagerInterface $em,
        protected AssetsManager $assetsManager,
    ) {
        $this->uow = $em->getUnitOfWork();
    }

    /**
     * Checks whether any fields that affect value/conversion have changed.
     */
    protected function areValuableFieldsUpdated($transaction): bool
    {
        $changeSet = $this->uow->getEntityChangeSet($transaction);

        $isExecutionDateChanged = isset($changeSet['executedAt']);
        $isAmountChanged = isset($changeSet['amount'])
            && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]);
        $isAccountChanged = isset($changeSet['account']);

        return $isExecutionDateChanged || $isAmountChanged || $isAccountChanged;
    }

    /**
     * Recalculates converted values for an Income using the new exchange rate engine.
     */
    protected function recalculateIncomeValue(Income $income): void
    {
        $transactionValue = $this->assetsManager->convert($income);

        $income->setConvertedValues($transactionValue);

        if ($this->uow->getEntityChangeSet($income) !== []) {
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(get_class($income)),
                $income
            );
        }
    }

    /**
     * Recalculates converted values for an Expense taking compensations into account.
     *
     * If $removedCompensationId is provided, only that compensation is considered.
     */
    protected function recalculateExpenseWithCompensationsValue(
        Expense $expense,
        int $removedCompensationId = null
    ): void {
        // Base expense value
        $transactionValue = $this->assetsManager->convert($expense);

        foreach ($expense->getCompensations() as $compensation) {
            if ($removedCompensationId && $compensation->getId() !== $removedCompensationId) {
                continue;
            }

            if ($compensation->getConvertedValues() === [] || $this->areValuableFieldsUpdated($compensation)) {
                $compensationValues = $this->assetsManager->convert($compensation);

                $compensation->setConvertedValues($compensationValues);

                if ($this->uow->getEntityChangeSet($compensation) !== []) {
                    $this->uow->recomputeSingleEntityChangeSet(
                        $this->em->getClassMetadata(Income::class),
                        $compensation
                    );
                }
            }

            $currencies = array_keys($transactionValue);
            foreach ($currencies as $currency) {
                $transactionValue[$currency] -= $compensation->getConvertedValue($currency);
            }
        }

        $expense->setConvertedValues($transactionValue);
        $changeSet = $this->uow->getEntityChangeSet($expense);

        if ($changeSet !== []) {
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(get_class($expense)),
                $expense
            );
        }
    }
}