<?php

namespace App\Service;

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
        private readonly EntityManagerInterface $em,
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
        foreach ($transfer->getTransactions() as $tx) {
            $tx->setConvertedValues($this->assetsManager->convert($tx));
        }
    }

    /**
     * Re-syncs transaction amounts/convertedValues when a transfer is updated.
     * Preserves existing transaction entities to keep IDs stable.
     */
    public function updateTransactions(Transfer $transfer): void
    {
        $fromExpense = $transfer->getFromExpense();
        $toIncome    = $transfer->getToIncome();
        $feeExpense  = $transfer->getFeeExpense();

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

        if ($feeExpense !== null) {
            if ($transfer->getFee() > 0) {
                $feeExpense
                    ->setAccount($transfer->getFeeAccount() ?? $transfer->getFrom())
                    ->setAmount((string) $transfer->getFee())
                    ->setExecutedAt($transfer->getExecutedAt());
                $feeExpense->setConvertedValues($this->assetsManager->convert($feeExpense));
            } else {
                $transfer->removeTransaction($feeExpense);
            }
        } elseif ($transfer->getFee() > 0) {
            // Fee was added on update — create it
            $this->initCategories();
            $fee = (new Expense())
                ->setAccount($transfer->getFeeAccount() ?? $transfer->getFrom())
                ->setCategory($this->feeExpenseCategory)
                ->setOwner($transfer->getOwner())
                ->setAmount((string) $transfer->getFee())
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

        $this->expenseTransferCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]) ?? throw new \RuntimeException('Required expense category "' . Category::CATEGORY_TRANSFER . '" not found.');

        $this->incomeTransferCategory = $this->em->getRepository(IncomeCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]) ?? throw new \RuntimeException('Required income category "' . Category::CATEGORY_TRANSFER . '" not found.');

        $this->feeExpenseCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER_FEE,
        ]) ?? throw new \RuntimeException('Required expense category "' . Category::CATEGORY_TRANSFER_FEE . '" not found.');
    }
}
