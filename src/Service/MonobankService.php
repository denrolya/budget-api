<?php

namespace App\Service;

use App\Entity\BankCardAccount;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use Carbon\Carbon;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

class MonobankService
{
    private EntityManagerInterface $em;

    private IncomeCategory $unknownIncomeCategory;

    private ExpenseCategory $unknownExpenseCategory;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->unknownIncomeCategory = $this->em->getRepository(IncomeCategory::class)->find(
            Category::INCOME_CATEGORY_ID_UNKNOWN
        );
        $this->unknownExpenseCategory = $this->em->getRepository(ExpenseCategory::class)->find(
            Category::EXPENSE_CATEGORY_ID_UNKNOWN
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function convertStatementItemToDraftTransaction(string $accountId, array $statementItem): ?Transaction
    {
        if (!$account = $this->em->getRepository(BankCardAccount::class)->findOneByMonobankId($accountId)) {
            throw new InvalidArgumentException('Account ID is not registered in system.');
        }

        $isIncome = $statementItem['amount'] > 0;
        $user = $account->getOwner();
        $amount = abs($statementItem['amount'] / 100);
        $note = $statementItem['description'].' '.(array_key_exists(
                'comment',
                $statementItem
            ) ? $statementItem['comment'] : '');

        $draftTransaction = $isIncome ? new Income(true) : new Expense(true);
        $draftTransaction
            ->setCategory($isIncome ? $this->unknownIncomeCategory : $this->unknownExpenseCategory)
            ->setAmount($amount)
            ->setAccount($account)
            ->setNote($note)
            ->setExecutedAt(Carbon::createFromTimestamp($statementItem['time']))
            ->setOwner($user);

        return $draftTransaction;
    }
}
