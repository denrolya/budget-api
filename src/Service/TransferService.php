<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transfer;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\IncomeCategoryRepository;
use RuntimeException;

final class TransferService
{
    private ?ExpenseCategory $expenseTransferCategory = null;
    private ?IncomeCategory $incomeTransferCategory = null;
    private ?ExpenseCategory $feeExpenseCategory = null;

    public function __construct(
        private readonly ExpenseCategoryRepository $expenseCategoryRepository,
        private readonly IncomeCategoryRepository $incomeCategoryRepository,
        private readonly AssetsManager $assetsManager,
    ) {
    }

    /**
     * Creates and attaches the bookkeeping transactions for a new transfer,
     * and immediately computes their convertedValues.
     *
     * Must be called after owner, from/to accounts, amount, rate, fee and
     * executedAt are all set on the Transfer.
     */
    public function createTransactions(Transfer $transfer): void
    {
        $this->initCategories();

        $owner = $transfer->getOwner();
        $from = $transfer->getFrom();
        $to = $transfer->getTo();
        $executedAt = $transfer->getExecutedAt();

        \assert(null !== $owner);
        \assert(null !== $from);
        \assert(null !== $to);
        \assert(null !== $executedAt);
        \assert(null !== $this->expenseTransferCategory);
        \assert(null !== $this->incomeTransferCategory);
        \assert(null !== $this->feeExpenseCategory);

        $expense = (new Expense())
            ->setAccount($from)
            ->setCategory($this->expenseTransferCategory)
            ->setOwner($owner)
            ->setAmount((string) $transfer->getAmount())
            ->setExecutedAt($executedAt);

        $income = (new Income())
            ->setAccount($to)
            ->setCategory($this->incomeTransferCategory)
            ->setOwner($owner)
            ->setAmount((string) ($transfer->getAmount() * $transfer->getRate()))
            ->setExecutedAt($executedAt);

        $transfer->addTransaction($expense);
        $transfer->addTransaction($income);

        if ($transfer->getFee() > 0) {
            $fee = (new Expense())
                ->setAccount($transfer->getFeeAccount() ?? $from)
                ->setCategory($this->feeExpenseCategory)
                ->setOwner($owner)
                ->setAmount((string) $transfer->getFee())
                ->setExecutedAt($executedAt);

            $transfer->addTransaction($fee);
        }

        // Compute convertedValues directly — no dependency on TransactionListener ordering.
        foreach ($transfer->getTransactions() as $transaction) {
            $transaction->setConvertedValues($this->assetsManager->convert($transaction));
        }
    }

    /**
     * Re-syncs transaction amounts/convertedValues when a transfer is updated.
     * Preserves existing transaction entities to keep IDs stable.
     */
    public function updateTransactions(Transfer $transfer): void
    {
        $from = $transfer->getFrom();
        $to = $transfer->getTo();
        $executedAt = $transfer->getExecutedAt();
        $owner = $transfer->getOwner();

        \assert(null !== $from);
        \assert(null !== $to);
        \assert(null !== $executedAt);
        \assert(null !== $owner);

        $fromExpense = $transfer->getFromExpense();
        $toIncome = $transfer->getToIncome();
        $feeExpense = $transfer->getFeeExpense();

        if (null !== $fromExpense) {
            $fromExpense
                ->setAccount($from)
                ->setAmount((string) $transfer->getAmount())
                ->setExecutedAt($executedAt);
            $fromExpense->setConvertedValues($this->assetsManager->convert($fromExpense));
        }

        if (null !== $toIncome) {
            $toIncome
                ->setAccount($to)
                ->setAmount((string) ($transfer->getAmount() * $transfer->getRate()))
                ->setExecutedAt($executedAt);
            $toIncome->setConvertedValues($this->assetsManager->convert($toIncome));
        }

        if (null !== $feeExpense) {
            if ($transfer->getFee() > 0) {
                $feeExpense
                    ->setAccount($transfer->getFeeAccount() ?? $from)
                    ->setAmount((string) $transfer->getFee())
                    ->setExecutedAt($executedAt);
                $feeExpense->setConvertedValues($this->assetsManager->convert($feeExpense));
            } else {
                $transfer->removeTransaction($feeExpense);
            }
        } elseif ($transfer->getFee() > 0) {
            $this->initCategories();
            \assert(null !== $this->feeExpenseCategory);
            $fee = (new Expense())
                ->setAccount($transfer->getFeeAccount() ?? $from)
                ->setCategory($this->feeExpenseCategory)
                ->setOwner($owner)
                ->setAmount((string) $transfer->getFee())
                ->setExecutedAt($executedAt);
            $transfer->addTransaction($fee);
            $fee->setConvertedValues($this->assetsManager->convert($fee));
        }
    }

    private function initCategories(): void
    {
        if (null !== $this->expenseTransferCategory) {
            return;
        }

        $this->expenseTransferCategory = $this->expenseCategoryRepository->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]) ?? throw new RuntimeException('Required expense category "' . Category::CATEGORY_TRANSFER . '" not found.');

        $this->incomeTransferCategory = $this->incomeCategoryRepository->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]) ?? throw new RuntimeException('Required income category "' . Category::CATEGORY_TRANSFER . '" not found.');

        $this->feeExpenseCategory = $this->expenseCategoryRepository->findOneBy([
            'name' => Category::CATEGORY_TRANSFER_FEE,
        ]) ?? throw new RuntimeException('Required expense category "' . Category::CATEGORY_TRANSFER_FEE . '" not found.');
    }
}
