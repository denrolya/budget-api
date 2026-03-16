<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\DataPersister\BudgetDataPersister;
use App\Repository\BudgetRepository;
use App\Traits\OwnableEntity;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\Table(name: 'budget')]
#[ApiResource(
    description: 'A budget plan for a specific time period. Contains budget lines that set spending limits per category.',
    operations: [
        new GetCollection(
            description: 'List all budgets ordered by start date (newest first).',
            normalizationContext: ['groups' => ['budget:collection:read']],
        ),
        new Post(
            description: 'Create a new budget. Set copiedFromId to clone lines from an existing budget.',
            processor: BudgetDataPersister::class,
            normalizationContext: ['groups' => ['budget:item:read']],
        ),
        new Get(
            description: 'Get a budget with all its budget lines.',
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => ['budget:item:read']],
        ),
        new Put(requirements: ['id' => '\d+']),
        new Delete(requirements: ['id' => '\d+']),
    ],
    denormalizationContext: ['groups' => ['budget:write']],
    order: ['startDate' => 'DESC'],
    paginationEnabled: false,
)]
class Budget implements OwnableInterface
{
    use OwnableEntity;

    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_YEARLY = 'yearly';
    public const PERIOD_CUSTOM = 'custom';

    private const ALLOWED_PERIOD_TYPES = [
        self::PERIOD_MONTHLY,
        self::PERIOD_YEARLY,
        self::PERIOD_CUSTOM,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['budget:collection:read', 'budget:item:read'])]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['budget:collection:read', 'budget:item:read', 'budget:write'])]
    private ?string $name = null;

    #[ApiProperty(description: 'Budget period type: monthly, yearly, or custom. Determines how startDate/endDate are interpreted.')]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::ALLOWED_PERIOD_TYPES)]
    #[ORM\Column(type: Types::STRING, length: 10)]
    #[Groups(['budget:collection:read', 'budget:item:read', 'budget:write'])]
    private string $periodType = self::PERIOD_MONTHLY;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Groups(['budget:collection:read', 'budget:item:read', 'budget:write'])]
    private DateTimeImmutable $startDate;

    #[Assert\NotNull]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Groups(['budget:collection:read', 'budget:item:read', 'budget:write'])]
    private DateTimeImmutable $endDate;

    /** @var Collection<int, BudgetLine> */
    #[ORM\OneToMany(
        mappedBy: 'budget',
        targetEntity: BudgetLine::class,
        cascade: ['persist', 'remove'],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[Groups(['budget:item:read'])]
    private Collection $lines;

    /**
     * Non-persisted field: when set during creation, lines are copied from the source budget.
     */
    #[ApiProperty(description: 'Set during POST to clone budget lines from an existing budget. Write-only, not persisted.')]
    #[Groups(['budget:write'])]
    private ?int $copiedFromId = null;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getPeriodType(): string
    {
        return $this->periodType;
    }

    public function setPeriodType(string $periodType): self
    {
        $this->periodType = $periodType;
        return $this;
    }

    public function getStartDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(DateTimeInterface $startDate): self
    {
        $this->startDate = DateTimeImmutable::createFromInterface($startDate);
        return $this;
    }

    public function getEndDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(DateTimeInterface $endDate): self
    {
        $this->endDate = DateTimeImmutable::createFromInterface($endDate);
        return $this;
    }

    /** @return Collection<int, BudgetLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(BudgetLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setBudget($this);
        }
        return $this;
    }

    public function removeLine(BudgetLine $line): self
    {
        $this->lines->removeElement($line);
        return $this;
    }

    public function getCopiedFromId(): ?int
    {
        return $this->copiedFromId;
    }

    public function setCopiedFromId(?int $copiedFromId): self
    {
        $this->copiedFromId = $copiedFromId;
        return $this;
    }
}
