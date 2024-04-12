<?php

namespace App\Message;

class UpdateAccountLogsOnTransactionRemoveMessage
{
    private int $transactionId;

    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
    }

    public function getTransactionId(): int
    {
        return $this->transactionId;
    }
}
