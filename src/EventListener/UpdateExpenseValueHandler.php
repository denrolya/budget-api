<?php

namespace App\EventListener;

use App\Entity\Expense;
use Psr\Cache\InvalidArgumentException;

final class UpdateExpenseValueHandler extends BaseUpdateTransactionValueHandler implements ToggleEnabledInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function prePersist(Expense $expense): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->recalculateExpenseWithCompensationsValue($expense);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function preUpdate(Expense $expense): void
    {
        if (!$this->isEnabled() || !$this->areValuableFieldsUpdated($expense)) {
            return;
        }

        $this->recalculateExpenseWithCompensationsValue($expense);
    }
}
