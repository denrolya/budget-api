<?php

namespace App\Service;

use App\Entity\BankCardAccount;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\TransactionInterface;
use App\Entity\User;
use Carbon\Carbon;
use Doctrine\ORM\EntityManagerInterface;

class MonobankService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function convertStatementItemToDraftTransaction(array $data): TransactionInterface
    {
        if(!$account = $this->em->getRepository(BankCardAccount::class)->findOneByMonobankId($data['account'])) {
            throw new \Exception('Account ID is not registered in system.');
        }

        $statementItem = $data['statementItem'];

        $isIncome = $statementItem['amount'] > 0;
        $unknownIncomeCategory = $this->em->getRepository(IncomeCategory::class)->find(39);
        $unknownExpenseCategory = $this->em->getRepository(ExpenseCategory::class)->find(17);
        $user = $this->em->getRepository(User::class)->find(2);
        $amount = abs($statementItem['amount'] / 100);
        $note = $statementItem['description'] . ' ' . (array_key_exists('comment', $statementItem) ? $statementItem['comment'] : '');

        $draftTransaction = $isIncome ? new Income(true) : new Expense(true);
        $draftTransaction
            ->setCategory($isIncome ? $unknownIncomeCategory : $unknownExpenseCategory)
            ->setAmount($amount)
            ->setAccount($account)
            ->setNote($note)
            ->setExecutedAt(Carbon::createFromTimestamp($statementItem['time']))
            ->setOwner($user);

        return $draftTransaction;
    }
}
