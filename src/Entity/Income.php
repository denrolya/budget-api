<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\IncomeRepository")
 */
class Income extends Transaction
{
    /**
     * Original expense which this income is compensating
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\Expense", inversedBy="compensations")
     * @ORM\JoinColumn(referencedColumnName="id")
     */
    private ?Expense $originalExpense;

    public function getType(): string
    {
        return TransactionInterface::INCOME;
    }

    public function isRevenue(): bool
    {
        return $this->getCategory()->getIsAffectingProfit();
    }

    public function getOriginalExpense(): ?Expense
    {
        return $this->originalExpense;
    }

    public function setOriginalExpense(Expense $expense): static
    {
        $this->originalExpense = $expense;

        return $this;
    }

    /**
     * @ORM\PrePersist()
     */
    public function updateAccountBalance(): void
    {
        $this->account->increaseBalance($this->amount);
    }

    /**
     * @ORM\PreRemove()
     */
    public function restoreAccountBalance(): void
    {
        if(!$this->getCanceledAt()) {
            $this->account->decreaseBalance($this->amount);
        }
    }
}
