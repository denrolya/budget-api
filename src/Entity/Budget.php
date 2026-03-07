<?php

namespace App\Entity;

use App\Repository\BudgetRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\Table(name: 'budget')]
class Budget
{
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_YEARLY  = 'yearly';
    public const PERIOD_CUSTOM  = 'custom';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $periodType = self::PERIOD_MONTHLY;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $endDate;

    #[ORM\OneToMany(mappedBy: 'budget', targetEntity: BudgetLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

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

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'periodType' => $this->periodType,
            'startDate'  => $this->startDate->format('Y-m-d'),
            'endDate'    => $this->endDate->format('Y-m-d'),
            'lines'      => $this->lines->map(fn(BudgetLine $l) => $l->toArray())->toArray(),
        ];
    }

    public function toListArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'periodType' => $this->periodType,
            'startDate'  => $this->startDate->format('Y-m-d'),
            'endDate'    => $this->endDate->format('Y-m-d'),
        ];
    }
}
