<?php

namespace App\EventListener;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transfer;
use Doctrine\ORM\EntityManagerInterface;

final class TransferCreateTransactionsHandler implements ToggleEnabledInterface
{
    use ToggleEnabledTrait;

    private ExpenseCategory $expenseTransferCategory;

    private IncomeCategory $incomeTransferCategory;

    private ExpenseCategory $feeExpenseCategory;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
        $this->expenseTransferCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]);
        $this->incomeTransferCategory = $this->em->getRepository(IncomeCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]);
        $this->feeExpenseCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER_FEE,
        ]);
    }

    public function prePersist(Transfer $transfer): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->createTransferTransactions($transfer);
    }

    private function createTransferTransactions(Transfer $transfer): void
    {
        $from = $transfer->getFrom();
        $to = $transfer->getTo();
        $amount = $transfer->getAmount();
        $executedAt = $transfer->getExecutedAt();

        $transfer->addTransaction(
            (new Expense())
                ->setCategory($this->expenseTransferCategory)
                ->setAccount($from)
                ->setAmount($amount)
                ->setExecutedAt($executedAt)
                ->setNote("Transfer Expense: $from to $to {$from->getCurrency()} $amount")
        );

        $transfer->addTransaction(
            (new Income())
                ->setCategory($this->incomeTransferCategory)
                ->setAccount($to)
                ->setAmount($amount * $transfer->getRate())
                ->setExecutedAt($executedAt)
                ->setNote("Transfer Income: $from to $to {$from->getCurrency()} $amount")
        );

        if ($transfer->getFee() > 0) {
            $feeExpense = (new Expense())
                ->setCategory($this->feeExpenseCategory)
                ->setAccount($transfer->getFeeAccount())
                ->setAmount($transfer->getFee())
                ->setNote("Transfer Fee: $from to $to {$from->getCurrency()} $amount")
                ->setExecutedAt($executedAt);

            $transfer->addTransaction($feeExpense);
        }
    }
}
