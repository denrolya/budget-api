<?php

namespace App\Message;

class UpdateAccountLogsOnTransactionUpdateMessage
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
