<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Serializer\Annotation\Groups;
use JetBrains\PhpStorm\Pure;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\ExpenseRepository")
 */
class Expense extends Transaction
{
    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Income", mappedBy="originalExpense", cascade={"persist"}, fetch="EXTRA_LAZY")
     */
    #[Groups(['transaction:list', 'debt:list'])]
    private array|null|ArrayCollection|PersistentCollection $compensations;

    #[Pure]
    public function __construct(bool $isDraft = false)
    {
        parent::__construct($isDraft);
        $this->compensations = new ArrayCollection();
    }

    public function getType(): string
    {
        return TransactionInterface::EXPENSE;
    }

    #[Pure]
    public function isLoss(): bool
    {
        return $this->getCategory()->getIsAffectingProfit();
    }

    public function getValue(): float
    {
        $value = $this->convertedValues[$this->getOwner()->getBaseCurrency()];

        if(empty($this->compensations)) {
            return $value;
        }

        $this->compensations->map(function(Income $compensation) use (&$value) {
            $value -= $compensation->getValue();
        });

        return $value;
    }

    public function getCompensations(): PersistentCollection|ArrayCollection|array|null
    {
        return $this->compensations;
    }

    #[Pure]
    public function hasCompensations(): bool
    {
        return !$this->compensations->isEmpty();
    }

    public function addCompensation(Income $income): self
    {
        if (!$this->compensations->contains($income)) {
            $this->compensations->add($income);
            $income->setOriginalExpense($this);
        }

        return $this;
    }

    public function removeCompensation(Income $income): self
    {
        if ($this->compensations->contains($income)) {
            $this->compensations->removeElement($income);
        }

        return $this;
    }

    /**
     * @ORM\PrePersist()
     */
    public function updateAccountBalance(): void
    {
        $this->account->decreaseBalance($this->amount);
    }

    /**
     * @ORM\PreRemove()
     */
    public function restoreAccountBalance(): void
    {
        if(!$this->getCanceledAt()) {
            $this->account->increaseBalance($this->amount);
        }
    }
}
