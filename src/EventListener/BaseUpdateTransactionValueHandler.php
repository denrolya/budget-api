<?php

namespace App\EventListener;

use App\Entity\Expense;
use App\Entity\Income;
use App\Service\AssetsManager;
use App\Service\FixerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Psr\Cache\InvalidArgumentException;

abstract class BaseUpdateTransactionValueHandler
{
    use ToggleEnabledTrait;

    protected UnitOfWork $uow;

    public function __construct(
        protected FixerService $fixerService,
        protected EntityManagerInterface $em,
        protected AssetsManager $assetsManager,
    ) {
        $this->uow = $em->getUnitOfWork();
    }

    protected function areValuableFieldsUpdated($transaction): bool
    {
        $changeSet = $this->uow->getEntityChangeSet($transaction);

        $isExecutionDateChanged = !empty($changeSet['executedAt']);
        $isAmountChanged = !empty($changeSet['amount']) && ((float)$changeSet['amount'][0] !== (float)$changeSet['amount'][1]);
        $isAccountChanged = !empty($changeSet['account']);

        return $isExecutionDateChanged || $isAmountChanged || $isAccountChanged;
    }

    protected function recalculateIncomeValue(Income $income): void
    {
        $transactionValue = $this->fixerService->convert(
            amount: $income->getAmount(),
            fromCurrency: $income->getCurrency(),
            executionDate: $income->getExecutedAt()
        );

        $income->setConvertedValues($transactionValue);
        if (!empty($this->uow->getEntityChangeSet($income))) {
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(get_class($income)),
                $income
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function recalculateExpenseWithCompensationsValue(Expense $expense, int $removedCompensationId = null): void
    {
        $transactionValue = $this->fixerService->convert(
            amount: $expense->getAmount(),
            fromCurrency: $expense->getCurrency(),
            executionDate: $expense->getExecutedAt()
        );

        foreach ($expense->getCompensations() as $compensation) {
            if ($removedCompensationId && $compensation->getId() !== $removedCompensationId) {
                continue;
            }

            if (empty($compensation->getConvertedValues()) || $this->areValuableFieldsUpdated($compensation)) {
                $compensationValues = $this->fixerService->convert(
                    amount: $compensation->getAmount(),
                    fromCurrency: $compensation->getCurrency(),
                    executionDate: $compensation->getExecutedAt()
                );

                $compensation->setConvertedValues($compensationValues);
                if (!empty($this->uow->getEntityChangeSet($compensation))) {
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
        if (!empty($changeSet)) {
            $this->uow->recomputeSingleEntityChangeSet(
                $this->em->getClassMetadata(get_class($expense)),
                $expense
            );
        }
    }
}
