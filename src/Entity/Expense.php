<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\ExpenseRepository")
 */
#[ApiResource(
    collectionOperations: [
        'post' => [
            'path' => '/transactions/expense',
            'normalization_context' => ['groups' => 'transaction:write'],
        ],
    ],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
    denormalizationContext: ['groups' => 'transaction:write'],
)]
class Expense extends Transaction
{
    /**
     * @ORM\OneToMany(targetEntity=Income::class, mappedBy="originalExpense", cascade={"persist"}, fetch="EXTRA_LAZY")
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'debt:collection:read'])]
    private ?Collection $compensations;

    /**
     * @Assert\IsTrue(message="Invalid category provided")
     */
    public function isExpenseCategory(): bool
    {
        return get_class($this->category) === ExpenseCategory::class;
    }

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

        $this->compensations->map(function (Income $compensation) use (&$value) {
            $value -= $compensation->getValue();
        });

        return $value;
    }

    public function getCompensations(): Collection
    {
        return $this->compensations;
    }

    public function hasCompensations(): bool
    {
        return !$this->compensations->isEmpty();
    }

    public function addCompensation(Income $income): self
    {
        if(!$this->compensations->contains($income)) {
            $this->compensations->add($income);
            $income->setOriginalExpense($this);
        }

        return $this;
    }

    public function removeCompensation(Income $income): self
    {
        if($this->compensations->contains($income)) {
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
