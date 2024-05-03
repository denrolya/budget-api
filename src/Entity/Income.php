<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\IncomeRepository;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: IncomeRepository::class)]
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
    #[ORM\ManyToOne(targetEntity: Expense::class, fetch: "EAGER", inversedBy: "compensations")]
    #[Groups(['transaction:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    private ?Expense $originalExpense = null;

    #[Assert\IsTrue(message: "Invalid category provided")]
    public function isExpenseCategory(): bool
    {
        if (!$this->category) {
            return false;
        }

        return get_class($this->category) === IncomeCategory::class;
    }

    #[Groups(['transaction:collection:read', 'debt:collection:read'])]
    public function getType(): string
    {
        return Transaction::INCOME;
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
}
