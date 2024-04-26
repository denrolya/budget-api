<?php

namespace App\EventListener;

use App\Entity\Expense;
use App\Entity\TransactionInterface;
use App\Service\FixerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Psr\Cache\InvalidArgumentException;

final class UpdateTransactionWithCompensationsValueHandler implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    private UnitOfWork $uow;

    public function __construct(
        private FixerService $fixerService,
        private EntityManagerInterface $em,
    ) {
        $this->uow = $em->getUnitOfWork();
    }

    public function updateTransactionWithCompensationsValue(TransactionInterface $transaction): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($transaction->isIncome() && $transaction->getOriginalExpense() !== null) {
            $this->recalculateTransactionValue($transaction->getOriginalExpense());
        }

        if ($transaction->isExpense() && $transaction->hasCompensations()) {
            $this->recalculateTransactionValue($transaction);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function recalculateTransactionValue(Expense $transaction): void
    {
        $transactionValue = $this->fixerService->convert(
            amount: $transaction->getAmount(),
            fromCurrency: $transaction->getCurrency(),
            executionDate: $transaction->getExecutedAt()
        );

        foreach ($transaction->getCompensations() as $compensation) {
            if (empty($compensation->getConvertedValues())) {
                $compensationValues = $this->fixerService->convert(
                    amount: $compensation->getAmount(),
                    fromCurrency: $compensation->getCurrency(),
                    executionDate: $compensation->getExecutedAt()
                );

                $compensation->setConvertedValues($compensationValues);
            }
            $currencies = array_keys($transactionValue);
            foreach ($currencies as $currency) {
                $transactionValue[$currency] -= $compensation->getConvertedValue($currency);
            }
        }

        $transaction->setConvertedValues($transactionValue);
        if (!empty($this->uow->getEntityChangeSet($transaction))) {
            $this->uow->recomputeSingleEntityChangeSet($this->em->getClassMetadata(Expense::class), $transaction);
        }
    }
}
