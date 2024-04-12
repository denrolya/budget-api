<?php

namespace App\MessageHandler;

use App\Message\UpdateAccountLogsOnTransactionUpdateMessage;
use App\Service\AccountLogManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UpdateAccountLogsOnTransactionRemoveMessageHandler
{
    public function __construct(
        private AccountLogManager $accountLogManager
    ) {
    }

    public function __invoke(UpdateAccountLogsOnTransactionUpdateMessage $message): void
    {
        $transactionId = $message->getTransactionId();
//        $this->accountLogManager->rebuildLogsForTransaction($transactionId);
    }
}
