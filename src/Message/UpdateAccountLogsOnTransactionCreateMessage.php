<?php

namespace App\Message;

use App\Entity\Account;
use Carbon\CarbonInterface;

class UpdateAccountLogsOnTransactionCreateMessage
{
    public function __construct(
        private Account $Account,
        private CarbonInterface $transactionExecutionDate
    ) {
    }

    public function getAccount(): Account
    {
        return $this->Account;
    }

    public function getTransactionExecutionDate(): CarbonInterface
    {
        return $this->transactionExecutionDate;
    }
}
