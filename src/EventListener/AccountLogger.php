<?php

namespace App\EventListener;

use App\Entity\Account;
use App\Entity\AccountLogEntry;
use App\Entity\Expense;
use App\Entity\Transaction;
use App\Entity\TransactionInterface;
use App\Service\FixerService;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

final class AccountLogger
{
    public const BATCH_SIZE = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private FixerService $fixer
    ) {
    }

    public function postPersist(TransactionInterface $transaction): void
    {
        $this->rebuildLogs(
            $transaction->getAccount(),
            $transaction->getExecutedAt(),
        );
    }

    private function rebuildLogs(Account $account, DateTimeInterface $executionDate): void
    {
        $this->removeAccountLogsAfterDate($account, $executionDate);
        $this->recreateLogs($account);
    }

    private function removeAccountLogsAfterDate(Account $account, DateTimeInterface $date): void
    {
        $logsToBeRemoved = $this
            ->em
            ->getRepository(AccountLogEntry::class)
            ->findWithinPeriod($date, null, null, $account);

        foreach ($logsToBeRemoved as $i => $log) {
            $this->em->remove($log);

            if (($i % self::BATCH_SIZE) === 0) {
                $this->em->flush();
            }
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
        $repo = $this->em->getRepository(Transaction::class);

        $transactionsBeforeLastLog = $repo->findBeforeLastLog($account);

        $transactions = $this->eliminateDuplicates(
            array_filter(
                $transactionsBeforeLastLog,
                static function (TransactionInterface $transaction) use ($removedTransactionId) {
                    return $transaction->getId() !== $removedTransactionId;
                }
            )
        );

        $balance = $account->getBalance();
        foreach ($transactions as $i => $transaction) {
            $transactionLogEntry = $this->createAccountLogFromTransaction($transaction, $balance);
            $this->em->persist($transactionLogEntry);

            $amount = $transaction->getAmount();
            $balance = $transaction instanceof Expense
                ? $balance + $amount
                : $balance - $amount;

            if (($i % self::BATCH_SIZE) === 0) {
                $this->em->flush();
            }
        }

        $this->em->flush();
    }

    private function eliminateDuplicates(array $transactions): array
    {
        $result = [];

        $dates = [];
        foreach ($transactions as $transaction) {
            $dates[$transaction->getExecutedAt()->toDateTimeString()][] = $transaction;
        }

        foreach ($dates as $date => $transactionsByDate) {
            if (count($transactionsByDate) === 1) {
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

            foreach ($transactionsByDate as $transaction) {
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

        uasort($result, static function (TransactionInterface $a, TransactionInterface $b) {
            // TODO: uasort(): Returning bool from comparison function is deprecated, return an integer less than, equal to, or greater than zero
            return $a->getExecutedAt()->isBefore($b->getExecutedAt());
        });

        return $result;
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

        return $log;
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

        $executionDate = $transaction->getExecutedAt();

        $this->rebuildLogs(
            $transaction->getAccount(),
            $executionDate,
        );

        if ($isAccountChanged) {
            $this->rebuildLogs(
                $changes['account'][0],
                $executionDate,
            );
        }
    }

    public function postRemove(TransactionInterface $transaction): void
    {
        $account = $transaction->getAccount();
        $executionDate = $transaction->getExecutedAt();

        $this->removeAccountLogsAfterDate($account, $executionDate);
        $this->recreateLogs($account, $transaction->getId());
    }
}
