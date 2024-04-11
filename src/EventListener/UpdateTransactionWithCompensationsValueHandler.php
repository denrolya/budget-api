<?php

namespace App\EventListener;

use App\Entity\Expense;
use App\Entity\TransactionInterface;
use App\Service\FixerService;
use Psr\Cache\InvalidArgumentException;

final class UpdateTransactionWithCompensationsValueHandler implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    private FixerService $fixerService;

    public function __construct(FixerService $fixerService)
    {
        $this->fixerService = $fixerService;
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
            $currencies = array_keys($transactionValue);
            foreach ($currencies as $currency) {
                $transactionValue[$currency] -= $compensation->getConvertedValue($currency);
            }
        }

        $transaction->setConvertedValues($transactionValue);
    }
}
