<?php

namespace App\MessageHandler;

use App\Message\UpdateAccountLogsOnTransactionCreateMessage;
use App\Service\AccountLogManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class UpdateAccountLogsOnTransactionCreateMessageHandler
{
    public function __construct(
        private AccountLogManager $accountLogManager
    ) {
    }

    public function __invoke(UpdateAccountLogsOnTransactionCreateMessage $message): void
    {
        $this->accountLogManager->rebuildLogs(
            $message->getAccount(),
            $message->getTransactionExecutionDate(),
        );
    }
}
