<?php

namespace App\EventListener;

use App\Entity\Income;
use Psr\Cache\InvalidArgumentException;

final class UpdateIncomeValueHandler extends BaseUpdateTransactionValueHandler implements ToggleEnabledInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function prePersist(Income $income): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($income->getOriginalExpense()) {
            $this->recalculateExpenseWithCompensationsValue($income->getOriginalExpense());
        } else {
            $this->recalculateIncomeValue($income);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function preUpdate(Income $income): void
    {
        if (!$this->isEnabled() || !$this->areValuableFieldsUpdated($income)) {
            return;
        }

        if ($income->getOriginalExpense()) {
            $this->recalculateExpenseWithCompensationsValue($income->getOriginalExpense());
        } else {
            $this->recalculateIncomeValue($income);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function preRemove(Income $income): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // TODO: Implement
        if ($income->getOriginalExpense()) {
            $this->recalculateExpenseWithCompensationsValue($income->getOriginalExpense(), $income->getId());
        } else {
            $this->recalculateIncomeValue($income);
        }
    }
}
