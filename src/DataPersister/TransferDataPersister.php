<?php

namespace App\DataPersister;

use ApiPlatform\Core\DataPersister\DataPersisterInterface;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transfer;
use Doctrine\ORM\EntityManagerInterface;

final class TransferDataPersister implements DataPersisterInterface
{
    private ExpenseCategory $expenseTransferCategory;

    private IncomeCategory $incomeTransferCategory;

    private ExpenseCategory $feeExpenseCategory;

    private DataPersisterInterface $decorated;

    public function __construct(EntityManagerInterface $em, DataPersisterInterface $decorated)
    {
        $this->decorated = $decorated;
        $this->expenseTransferCategory = $em->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]);
        $this->incomeTransferCategory = $em->getRepository(IncomeCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER,
        ]);
        $this->feeExpenseCategory = $em->getRepository(ExpenseCategory::class)->findOneBy([
            'name' => Category::CATEGORY_TRANSFER_FEE,
        ]);
    }

    public function supports($data): bool
    {
        return $data instanceof Transfer;
    }

    /**
     * @param Transfer $data
     * @return object|null
     */
    public function persist($data): ?object
    {
        $from = $data->getFrom();
        $to = $data->getTo();
        $amount = $data->getAmount();
        $executedAt = $data->getExecutedAt();

        $fromExpense = new Expense();
        $toIncome = new Income();
        $data->setFromExpense(
            $fromExpense
                ->setCategory($this->expenseTransferCategory)
                ->setAccount($from)
                ->setAmount($amount)
                ->setExecutedAt($executedAt)
                ->setNote("Transfer Expense: $from to $to {$from->getCurrency()} $amount")
        );

        $data->setToIncome(
            $toIncome
                ->setCategory($this->incomeTransferCategory)
                ->setAccount($to)
                ->setAmount($amount * $data->getRate())
                ->setExecutedAt($executedAt)
                ->setNote("Transfer Income: $from to $to {$from->getCurrency()} $amount")
        );

        if($data->getFee() > 0) {
            $feeExpense = (new Expense())
                ->setCategory($this->feeExpenseCategory)
                ->setAccount($data->getFeeAccount())
                ->setAmount($data->getFee())
                ->setNote("Transfer Fee: $from to $to {$from->getCurrency()} $amount")
                ->setExecutedAt($executedAt);

            $data->setFeeExpense($feeExpense);
        }

        return $this->decorated->persist($data);
    }

    /**
     * @param Transfer $data
     * @return void
     */
    public function remove($data): void
    {
        $this->decorated->remove($data);
    }
}
