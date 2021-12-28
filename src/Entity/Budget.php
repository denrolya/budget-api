<?php

namespace App\Entity;

use App\Repository\BudgetRepository;
use App\Traits\OwnableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BudgetRepository::class)
 * @ORM\HasLifecycleCallbacks()
 */
class Budget implements OwnableInterface
{
    use OwnableEntity, TimestampableEntity;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id;

    /**
     *
     * @ORM\Column(type="datetime")
     */
    private ?CarbonImmutable $beginningAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?CarbonImmutable $endingAt;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\BudgetGoal", mappedBy="budget", cascade={"persist", "remove"})
     */
    private $goals;

    public function __construct()
    {
        $this->goals = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBeginningAt(): ?CarbonInterface
    {
        if($this->beginningAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->beginningAt->getTimestamp(), $this->beginningAt->getTimezone());
        }

        return $this->beginningAt;
    }

    public function setBeginningAt(CarbonInterface $beginningAt): static
    {
        $this->beginningAt = $beginningAt;

        return $this;
    }

    public function getEndingAt(): ?CarbonInterface
    {
        if($this->endingAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->endingAt->getTimestamp(), $this->endingAt->getTimezone());
        }

        return $this->endingAt;
    }

    public function setEndingAt(CarbonInterface $endingAt): static
    {
        $this->endingAt = $endingAt;

        return $this;
    }

    public function getDateInterval(): ?CarbonPeriod
    {
        if (!$this->beginningAt instanceof DateTimeInterface || !$this->endingAt instanceof DateTimeInterface) {
            return null;
        }

        return CarbonPeriod::create(
            $this->getBeginningAt(),
            $this->getEndingAt()
        );
    }

    public function addGoal(BudgetGoal $goal): static
    {
        if (!$this->goals->contains($goal)) {
            $this->goals->add($goal);
        }

        return $this;
    }

    public function removeGoal(BudgetGoal $goal): static
    {
        if ($this->goals->contains($goal)) {
            $this->goals->removeElement($goal);
        }

        return $this;
    }

    public function getGoals(): ArrayCollection
    {
        return $this->goals;
    }
}
