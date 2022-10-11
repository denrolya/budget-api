<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\IncomeRepository")
 */
#[ApiResource(
    collectionOperations: [
        'post' => [
            'path' => '/transactions/income',
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
class Income extends Transaction
{
    /**
     * Original expense which this income is compensating
     *
     * @ORM\ManyToOne(targetEntity=Expense::class, inversedBy="compensations", fetch="EAGER")
     */
    #[Groups(['transaction:collection:read'])]
    private ?Expense $originalExpense;

    /**
     * @Assert\IsTrue(message="Invalid category provided")
     */
    public function isExpenseCategory(): bool
    {
        if(!$this->category) {
            return false;
        }

        return get_class($this->category) === IncomeCategory::class;
    }

    #[Groups(['transaction:collection:read', 'debt:collection:read'])]
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

    public function setOriginalExpense(Expense $expense): self
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
        $this->account->decreaseBalance($this->amount);
    }
}
