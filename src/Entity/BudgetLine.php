<?php

namespace App\Entity;

use App\Repository\BudgetLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BudgetLineRepository::class)]
#[ORM\Table(name: 'budget_line')]
#[ORM\UniqueConstraint(name: 'uq_budget_category', columns: ['budget_id', 'category_id'])]
class BudgetLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Budget::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Budget $budget;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    private string $plannedAmount = '0';

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $plannedCurrency = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudget(): Budget
    {
        return $this->budget;
    }

    public function setBudget(Budget $budget): self
    {
        $this->budget = $budget;
        return $this;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getPlannedAmount(): float
    {
        return (float) $this->plannedAmount;
    }

    public function setPlannedAmount(string|float|int $plannedAmount): self
    {
        $this->plannedAmount = (string) $plannedAmount;
        return $this;
    }

    public function getPlannedCurrency(): string
    {
        return $this->plannedCurrency;
    }

    public function setPlannedCurrency(string $plannedCurrency): self
    {
        $this->plannedCurrency = $plannedCurrency;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): self
    {
        $this->note = $note;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'categoryId'      => $this->category->getId(),
            'plannedAmount'   => $this->getPlannedAmount(),
            'plannedCurrency' => $this->plannedCurrency,
            'note'            => $this->note,
        ];
    }
}
