<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\DataPersister\BudgetLineDataPersister;
use App\Repository\BudgetLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BudgetLineRepository::class)]
#[ORM\Table(name: 'budget_line')]
#[ORM\UniqueConstraint(name: 'uq_budget_category', columns: ['budget_id', 'category_id'])]
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/budgets/{budgetId}/lines',
            uriVariables: [
                'budgetId' => new Link(fromClass: Budget::class, toProperty: 'budget'),
            ],
            read: false,
            processor: BudgetLineDataPersister::class,
            denormalizationContext: ['groups' => ['budget-line:write']],
            normalizationContext: ['groups' => ['budget-line:read']],
        ),
        new Put(
            uriTemplate: '/budgets/{budgetId}/lines/{id}',
            uriVariables: [
                'budgetId' => new Link(fromClass: Budget::class, toProperty: 'budget'),
                'id' => new Link(fromClass: BudgetLine::class),
            ],
            processor: BudgetLineDataPersister::class,
            denormalizationContext: ['groups' => ['budget-line:write']],
            normalizationContext: ['groups' => ['budget-line:read']],
        ),
        new Delete(
            uriTemplate: '/budgets/{budgetId}/lines/{id}',
            uriVariables: [
                'budgetId' => new Link(fromClass: Budget::class, toProperty: 'budget'),
                'id' => new Link(fromClass: BudgetLine::class),
            ],
            processor: BudgetLineDataPersister::class,
        ),
    ],
    paginationEnabled: false,
)]
class BudgetLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['budget:item:read', 'budget-line:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Budget::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Budget $budget;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    /**
     * Non-persisted field: used during POST denormalization to resolve the Category entity.
     */
    #[Groups(['budget-line:write'])]
    private ?int $categoryId = null;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    #[Groups(['budget:item:read', 'budget-line:read', 'budget-line:write'])]
    #[Serializer\Type(Types::FLOAT)]
    #[ApiProperty(builtinTypes: [new \Symfony\Component\PropertyInfo\Type(builtinType: 'float')])]
    private string $plannedAmount = '0';

    #[Assert\NotBlank]
    #[Assert\Length(max: 10)]
    #[ORM\Column(type: Types::STRING, length: 10)]
    #[Groups(['budget:item:read', 'budget-line:read', 'budget-line:write'])]
    private string $plannedCurrency = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['budget:item:read', 'budget-line:read', 'budget-line:write'])]
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

    #[Groups(['budget:item:read', 'budget-line:read'])]
    public function getCategoryId(): ?int
    {
        if (isset($this->category)) {
            return $this->category->getId();
        }

        return $this->categoryId;
    }

    public function setCategoryId(?int $categoryId): self
    {
        $this->categoryId = $categoryId;
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
}
