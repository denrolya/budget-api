<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\IncomeRepository;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: IncomeRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/transactions/income', normalizationContext: ['groups' => 'transaction:collection:read']),
    ],
    denormalizationContext: ['groups' => 'transaction:write'],
)]
class Income extends Transaction
{
    #[ORM\ManyToOne(targetEntity: Expense::class, fetch: 'EAGER', inversedBy: 'compensations')]
    #[Groups(['transaction:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    private ?Expense $originalExpense = null;

    #[Assert\IsTrue(message: 'Invalid category provided')]
    public function isExpenseCategory(): bool
    {
        return $this->category instanceof IncomeCategory;
    }

    #[Groups(['transaction:collection:read', 'debt:collection:read', 'transfer:collection:read'])]
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
