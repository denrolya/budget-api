<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\ExpenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JetBrains\PhpStorm\Pure;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ApiResource(
    collectionOperations: [
        'post' => [
            'path' => '/transactions/expense',
            'normalization_context' => ['groups' => 'transaction:collection:read'],
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
    #[ORM\OneToMany(mappedBy: "originalExpense", targetEntity: Income::class, cascade: ["persist", "remove"], fetch: "EXTRA_LAZY")]
    #[Groups(['transaction:collection:read', 'transaction:write', 'debt:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    private ?Collection $compensations;

    #[Assert\IsTrue(message: "Invalid category provided")]
    public function isExpenseCategory(): bool
    {
        return $this->getCategory()->getType() === 'expense';
    }

    #[Pure]
    public function __construct(bool $isDraft = false)
    {
        parent::__construct($isDraft);
        $this->compensations = new ArrayCollection();
    }

    #[Groups(['transaction:collection:read', 'debt:collection:read'])]
    public function getType(): string
    {
        return TransactionInterface::EXPENSE;
    }

    public function isLoss(): bool
    {
        return $this->getCategory()->getIsAffectingProfit();
    }

    public function getCompensations(): Collection
    {
        return $this->compensations;
    }

    public function hasCompensations(): bool
    {
        return $this->compensations->count() > 0;
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

    #[ORM\PrePersist]
    public function updateAccountBalance(): void
    {
        $this->account->decreaseBalance($this->amount);
    }

    #[ORM\PreRemove]
    public function restoreAccountBalance(): void
    {
        $this->account->increaseBalance($this->amount);
    }
}
