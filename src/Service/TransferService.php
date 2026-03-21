<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transfer;
use Doctrine\ORM\EntityManagerInterface;

final class TransferService
{
    private ?ExpenseCategory $expenseTransferCategory = null;
    private ?IncomeCategory $incomeTransferCategory = null;
    private ?ExpenseCategory $feeExpenseCategory = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetsManager $assetsManager,
    ) {
    }

    /**
     * Creates and attaches the bookkeeping transactions for a new transfer,
     * and immediately computes their convertedValues.
     *
     * @param array<array{amount: string|float|int, account: Account}> $fees
     */
    public function createTransactions(Transfer $transfer, array $fees = []): void
    {
        $this->initCategories();

        $owner      = $transfer->getOwner();
        $from       = $transfer->getFrom();
        $to         = $transfer->getTo();
        $executedAt = $transfer->getExecutedAt();

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

        foreach ($fees as $feeData) {
            $fee = (new Expense())
                ->setAccount($feeData['account'])
                ->setCategory($this->feeExpenseCategory)
                ->setOwner($owner)
                ->setAmount((string) $feeData['amount'])
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
     * Preserves existing core transaction entities to keep IDs stable.
     * Fee transactions are removed and recreated from the provided fees array.
     *
     * @param array<array{amount: string|float|int, account: Account}> $fees
     */
    public function updateTransactions(Transfer $transfer, array $fees = []): void
    {
        $fromExpense = $transfer->getFromExpense();
        $toIncome    = $transfer->getToIncome();

        if ($fromExpense !== null) {
            $fromExpense
                ->setAccount($transfer->getFrom())
                ->setAmount((string) $transfer->getAmount())
                ->setExecutedAt($transfer->getExecutedAt());
            $fromExpense->setConvertedValues($this->assetsManager->convert($fromExpense));
        }

        if ($toIncome !== null) {
            $toIncome
                ->setAccount($transfer->getTo())
                ->setAmount((string) ($transfer->getAmount() * $transfer->getRate()))
                ->setExecutedAt($transfer->getExecutedAt());
            $toIncome->setConvertedValues($this->assetsManager->convert($toIncome));
        }

        // Remove all existing fee transactions
        foreach ($transfer->getFeeExpenses() as $existingFee) {
            $transfer->removeTransaction($existingFee);
        }

        // Recreate from provided fees
        $this->initCategories();
        foreach ($fees as $feeData) {
            $fee = (new Expense())
                ->setAccount($feeData['account'])
                ->setCategory($this->feeExpenseCategory)
                ->setOwner($transfer->getOwner())
                ->setAmount((string) $feeData['amount'])
                ->setExecutedAt($transfer->getExecutedAt());
            $transfer->addTransaction($fee);
            $fee->setConvertedValues($this->assetsManager->convert($fee));
        }
    }

    private function initCategories(): void
    {
        if ($this->expenseTransferCategory !== null) {
            return;
        }

        $this->expenseTransferCategory = $this->entityManager->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]) ?? throw new \RuntimeException('Required expense category "' . Category::CATEGORY_TRANSFER . '" not found.');

        $this->incomeTransferCategory = $this->entityManager->getRepository(IncomeCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]) ?? throw new \RuntimeException('Required income category "' . Category::CATEGORY_TRANSFER . '" not found.');

        $this->feeExpenseCategory = $this->entityManager->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER_FEE,
        ]) ?? throw new \RuntimeException('Required expense category "' . Category::CATEGORY_TRANSFER_FEE . '" not found.');
    }
}
