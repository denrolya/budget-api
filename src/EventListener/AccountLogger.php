<?php

namespace App\EventListener;

use App\Entity\TransactionInterface;
use App\Message\UpdateAccountLogsOnTransactionCreateMessage;
use App\Message\UpdateAccountLogsOnTransactionUpdateMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class AccountLogger
{
    public function __construct(
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
    ) {
    }

    public function postPersist(TransactionInterface $transaction): void
    {
//        $this->bus->dispatch(new UpdateAccountLogsOnTransactionCreateMessage($transaction->getAccount(), $transaction->getExecutedAt()));
    }

    /**
     * Should invoke logging only if account, amount or execution date was changed;
     *
     * @param TransactionInterface $transaction
     * @return void
     */
    public function postUpdate(TransactionInterface $transaction): void
    {
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();

        $changes = $uow->getEntityChangeSet($transaction);

        $isAccountChanged = !empty($changes['account']);
        $isExecutionDateChanged = !empty($changes['executedAt']) && ($changes['executedAt'][0]->getTimestamp(
                ) !== $changes['executedAt'][1]->getTimestamp());
        $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

        if (!$isAmountChanged && !$isAccountChanged && !$isExecutionDateChanged) {
            return;
        }

        // TODO: Debug if execution date is really changing

        //        $executionDate = $transaction->getExecutedAt();
//
//        $this->rebuildLogs(
//            $transaction->getAccount(),
//            $executionDate,
//        );
//
//        if ($isAccountChanged) {
//            $this->rebuildLogs(
//                $changes['account'][0],
//                $executionDate,
//            );
//        }

//        $this->bus->dispatch(new UpdateAccountLogsOnTransactionUpdateMessage($transaction->getId()));
    }

    public function postRemove(TransactionInterface $transaction): void
    {
//        $account = $transaction->getAccount();
//        $executionDate = $transaction->getExecutedAt();

//        $this->removeAccountLogsAfterDate($account, $executionDate);
//        $this->recreateLogs($account, $transaction->getId());

//        $this->bus->dispatch(new UpdateAccountLogsOnTransactionCreateMessage($transaction->getId()));
    }
}
