<?php

namespace App\Entity;

use App\Repository\BudgetGoalRepository;
use App\Traits\OwnableValuableEntity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass=BudgetGoalRepository::class)
 */
class BudgetGoal implements OwnableInterface, ValuableInterface
{
    use OwnableValuableEntity;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id;

    /**
     * @ORM\ManyToMany(targetEntity=Category::class, inversedBy="budgetGoals")
     */
    private $category;

    /**
     * @ORM\ManyToMany(targetEntity=Transaction::class, inversedBy="budgetGoals")
     */
    private $transactions;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5)
     */
    private $goalValue;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Budget", inversedBy="goals")
     * @ORM\JoinColumn(referencedColumnName="id")
     */
    private $budget;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $note;

    public function __construct()
    {
        $this->category = new ArrayCollection();
        $this->transactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): Collection
    {
        return $this->category;
    }

    public function addCategory(Category $category): static
    {
        if (!$this->category->contains($category)) {
            $this->category[] = $category;
        }

        return $this;
    }

    public function removeCategory(Category $category): static
    {
        $this->category->removeElement($category);

        return $this;
    }

    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions[] = $transaction;
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        $this->transactions->removeElement($transaction);

        return $this;
    }

    public function getGoalValue(): ?string
    {
        return $this->goalValue;
    }

    public function setGoalValue(string $goalValue): static
    {
        $this->goalValue = $goalValue;

        return $this;
    }

    public function getBudget(): Budget
    {
        return $this->budget;
    }

    public function setBudget(Budget $budget): static
    {
        $this->budget = $budget;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /*===    INTERFACE METHODS    ===*/
    public function getCurrencyCode(): string
    {
        return $this->getOwner()->getBaseCurrency();
    }

    public function getValuableField(): string
    {
        return 'goalValue';
    }
}
