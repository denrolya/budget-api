<?php

namespace App\EventListener;

use App\Entity\Transaction;
use App\Message\UpdateAccountLogsOnTransactionCreateMessage;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Component\Messenger\MessageBusInterface;

final class AccountLogger implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    public function __construct(
        private MessageBusInterface $bus,
    ) {
        $this->setEnabled(false);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Transaction) {
                $this->postPersist($entity);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Transaction) {
                $this->postUpdate($entity);
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Transaction) {
                $this->postRemove($entity);
            }
        }
    }

    public function postPersist(Transaction $transaction): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->bus->dispatch(
            new UpdateAccountLogsOnTransactionCreateMessage($transaction->getAccount(), $transaction->getExecutedAt())
        );
    }

    /**
     * Should invoke logging only if account, amount or execution date was changed;
     *
     * @param Transaction $transaction
     * @return void
     */
    public function postUpdate(Transaction $transaction): void
    {
        if (!$this->isEnabled()) {
            return;
        }

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

    public function postRemove(Transaction $transaction): void
    {
        if (!$this->isEnabled()) {
            return;
        }

//        $account = $transaction->getAccount();
//        $executionDate = $transaction->getExecutedAt();

//        $this->removeAccountLogsAfterDate($account, $executionDate);
//        $this->recreateLogs($account, $transaction->getId());

//        $this->bus->dispatch(new UpdateAccountLogsOnTransactionCreateMessage($transaction->getId()));
    }
}
