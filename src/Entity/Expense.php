<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\ExpenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/transactions/expense', normalizationContext: ['groups' => 'transaction:collection:read']),
    ],
    denormalizationContext: ['groups' => 'transaction:write'],
)]
class Expense extends Transaction
{
    /** @var Collection<int, Income> */
    #[ORM\OneToMany(mappedBy: 'originalExpense', targetEntity: Income::class, cascade: ['persist', 'remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    #[Groups(['transaction:collection:read', 'transaction:write', 'debt:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    private Collection $compensations;

    #[Assert\IsTrue(message: 'Invalid category provided')]
    public function isExpenseCategory(): bool
    {
        return 'expense' === $this->getCategory()->getType();
    }

    public function __construct(bool $isDraft = false)
    {
        parent::__construct($isDraft);
        $this->compensations = new ArrayCollection();
    }

    #[Groups(['transaction:collection:read', 'debt:collection:read', 'transfer:collection:read'])]
    public function getType(): string
    {
        return Transaction::EXPENSE;
    }

    public function isLoss(): bool
    {
        return $this->getCategory()->getIsAffectingProfit();
    }

    /**
     * @return Collection<int, Income>
     */
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
}
