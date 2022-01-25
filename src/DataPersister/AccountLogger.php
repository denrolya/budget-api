<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\ContextAwareDataPersisterInterface;
use ApiPlatform\Core\DataPersister\ResumableDataPersisterInterface;
use App\Entity\AccountLogEntry;
use App\Entity\Expense;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\FixerService;
use App\Entity\Account;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

final class AccountLogger implements ContextAwareDataPersisterInterface, ResumableDataPersisterInterface
{
    public function __construct(
        private ContextAwareDataPersisterInterface $decorated,
        private EntityManagerInterface $em,
        private FixerService $fixer
    )
    {
    }

    public function supports($data, array $context = []): bool
    {
        return $data instanceof TransactionInterface;
    }

    public function persist($data, array $context = [])
    {
        $result = $this->decorated->persist($data);

        if (isset($context['previous_data'])) {
            $this->onUpdate($data);
        } else {
            $this->onPersist($data);
        }

        return $result;
    }

    public function remove($data, array $context = []): void
    {
        $this->decorated->remove($data);

        $this->onRemove($data);
    }

    public function resumable(array $context = []): bool
    {
        return true;
    }

    private function onPersist(TransactionInterface $transaction): void
    {
        $this->rebuildLogs(
            $transaction->getAccount(),
            $transaction->getExecutedAt(),
        );
    }

    /**
     * Should invoke logging only if account, amount or execution date was changed;
     * TODO: Make a use of $context['previous_data'] instead of UOW
     *
     * @param TransactionInterface $transaction
     * @return void
     */
    private function onUpdate(TransactionInterface $transaction): void
    {
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();

        $changes = $uow->getEntityChangeSet($transaction);

        $isAccountChanged = !empty($changes['account']);
        $isExecutionDateChanged = !empty($changes['executedAt']) && ($changes['executedAt'][0]->getTimestamp() !== $changes['executedAt'][1]->getTimestamp());
        $isAmountChanged = !empty($changes['amount']) && ((float)$changes['amount'][0] !== (float)$changes['amount'][1]);

        if(!$isAmountChanged && !$isAccountChanged && !$isExecutionDateChanged) {
            return;
        }

        $executionDate = $transaction->getExecutedAt();

        $this->rebuildLogs(
            $transaction->getAccount(),
            $executionDate,
        );

        if($isAccountChanged) {
            $this->rebuildLogs(
                $changes['account'][0],
                $executionDate,
            );
        }
    }

    private function onRemove(TransactionInterface $transaction): void
    {
        $account = $transaction->getAccount();
        $executionDate = $transaction->getExecutedAt();

        $this->removeAccountLogsAfterDate($account, $executionDate);
        $this->recreateLogs($account, $transaction->getId());
    }

    private function rebuildLogs(Account $account, DateTimeInterface $executionDate): void
    {
        $this->removeAccountLogsAfterDate($account, $executionDate);
        $this->recreateLogs($account);
    }

    private function removeAccountLogsAfterDate(Account $account, DateTimeInterface $date): void
    {
        $logsToBeRemoved = $account->getLogs()->filter(static function (AccountLogEntry $entry) use ($date) {
            return $entry->getCreatedAt()->greaterThanOrEqualTo($date);
        })->toArray();

        foreach($logsToBeRemoved as $log) {
            $this->em->remove($log);
        }
        $this->em->flush();
    }

    /**
     * Since this can be called on PRE remove listener canceledAt is not yet set on the transaction
     * Hence function omits this transaction while recreating logs
     *
     * @param Account $account
     * @param int|null $removedTransactionId
     * @return void
     */
    private function recreateLogs(Account $account, ?int $removedTransactionId = null): void
    {
        $transactions = $this->eliminateDuplicates(
            array_filter(
                $this->em->getRepository(Transaction::class)->findBeforeLastLog($account),
                static function (TransactionInterface $transaction) use ($removedTransactionId) {
                    return $transaction->getId() !== $removedTransactionId;
                }
            )
        );

        $balance = $account->getBalance();
        foreach($transactions as $transaction) {
            $transactionLogEntry = $this->createAccountLogFromTransaction($transaction, $balance);

            $amount = $transaction->getAmount();
            $balance = $transaction instanceof Expense
                ? $balance + $amount
                : $balance - $amount;
            $this->em->persist($transactionLogEntry);
        }

        $this->em->flush();
    }

    private function createAccountLogFromTransaction(TransactionInterface $transaction, $balance): AccountLogEntry
    {
        $account = $transaction->getAccount();

        $convertedValues = $this->fixer->convert(
            $balance,
            $account->getCurrency(),
            $transaction->getExecutedAt(),
        );

        $log = new AccountLogEntry(
            $account,
            $balance,
            $convertedValues,
            $transaction->getExecutedAt()
        );
        $account->addLog($log);

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    private function eliminateDuplicates(array $transactions): array
    {
        $result = [];

        $dates = [];
        foreach($transactions as $transaction) {
            $dates[$transaction->getExecutedAt()->toDateTimeString()][] = $transaction;
        }

        foreach($dates as $date => $transactionsByDate) {
            if(count($transactionsByDate) === 1) {
                $result = [
                    ...$result,
                    ...$transactionsByDate,
                ];
                continue;
            }

            $newTransaction = new Expense();
            $newTransaction
                ->setAccount($transactionsByDate[0]->getAccount())
                ->setExecutedAt($transactionsByDate[0]->getExecutedAt());

            foreach($transactionsByDate as $transaction) {
                $newTransaction->setAmount(
                    $transaction instanceof Expense
                        ? $newTransaction->getAmount() + $transaction->getAmount()
                        : $newTransaction->getAmount() - $transaction->getAmount()
                );
            }

            $result = [
                ...$result,
                $newTransaction,
            ];
        }

        usort($result, static function (TransactionInterface $a, TransactionInterface $b) {
            return $a->getExecutedAt()->isBefore($b->getExecutedAt());
        });

        return $result;
    }
}
