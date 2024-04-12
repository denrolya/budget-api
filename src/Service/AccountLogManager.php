<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountLogEntry;
use App\Entity\Expense;
use App\Entity\TransactionInterface;
use App\Repository\AccountLogEntryRepository;
use App\Repository\TransactionRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TODO: To be rewritten
 */
final class AccountLogManager
{
    public const BATCH_SIZE = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private TransactionRepository $transactionRepository,
        private AccountLogEntryRepository $accountLogEntryRepository,
        private FixerService $fixerService,
    ) {
    }

    public function rebuildLogs(Account $account, DateTimeInterface $executionDate): void
    {
        $this->removeAccountLogsAfterDate($account, $executionDate);
        $this->recreateLogs($account);
    }

    public function removeAccountLogsAfterDate(Account $account, DateTimeInterface $date): void
    {
        $logsToBeRemoved = $this->accountLogEntryRepository->findWithinPeriod($date, null, null, $account);

        foreach ($logsToBeRemoved as $i => $log) {
            $this->em->remove($log);

//            if (($i % self::BATCH_SIZE) === 0) {
//                $this->em->flush();
//            }
        }
//        $this->em->flush();
    }

    /**
     * Since this can be called on PRE remove listener canceledAt is not yet set on the transaction
     * Hence function omits this transaction while recreating logs
     *
     * @param Account $account
     * @param int|null $removedTransactionId
     * @return void
     */
    public function recreateLogs(Account $account, ?int $removedTransactionId = null): void
    {
        $transactionsBeforeLastLog = $this->transactionRepository->findBeforeLastLog($account);

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

//            if (($i % self::BATCH_SIZE) === 0) {
//                $this->em->flush();
//            }
        }

//        $this->em->flush();
    }

    public function eliminateDuplicates(array $transactions): array
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

    public function createAccountLogFromTransaction(TransactionInterface $transaction, $balance): AccountLogEntry
    {
        $account = $transaction->getAccount();

        $convertedValues = $this->fixerService->convert(
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
}
