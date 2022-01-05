<?php

namespace App\Entity;

use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @Gedmo\SoftDeleteable(fieldName="closedAt", timeAware=false, hardDelete=false)
 *
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\DebtRepository")
 */
class Debt implements OwnableInterface, ValuableInterface
{
    use TimestampableEntity, OwnableValuableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    #[Groups(['debt:collection:read'])]
    private ?int $id;

    /**
     * TODO: getValues
     *
     * @ORM\Column(type="json", nullable=false)
     */
    #[Groups(['debt:collection:read'])]
    protected ?array $convertedValues = [];

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['debt:collection:read'])]
    protected ?DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="text", nullable=true)
     */

    protected ?string $note;

    /**
     * A debtor is an entity that owes a debt to another entity
     *
     * @ORM\Column(type="string", length=255)
     */
    #[Groups(['debt:collection:read'])]
    private ?string $debtor;


    /**
     * @ORM\Column(type="string", length=3)
     */
    #[Groups(['debt:collection:read'])]
    private ?string $currency;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5)
     */
    #[Groups(['debt:collection:read'])]
    private float $balance = 0;

    /**
     * @ORM\ManyToMany(targetEntity="Transaction")
     * @ORM\JoinTable(name="debt_transactions",
     *      joinColumns={@ORM\JoinColumn(name="debt_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="transaction_id", referencedColumnName="id", unique=true)},
     *      )
     */
    #[Groups(['debt:collection:read'])]
    private array|ArrayCollection $transactions;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['debt:collection:read'])]
    private ?DateTimeInterface $closedAt;

    #[Pure]
    public function __construct(string $debtor = null)
    {
        $this->debtor = $debtor;
        $this->transactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDebtor(): ?string
    {
        return $this->debtor;
    }

    public function setDebtor(string $debtor): self
    {
        $this->debtor = $debtor;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getBalance(): float
    {
        return $this->balance;
    }

    public function setBalance(float $balance): self
    {
        $this->balance = $balance;

        return $this;
    }

    public function increaseBalance(float $amount): self
    {
        $this->balance += $amount;

        return $this;
    }

    public function decreaseBalance(float $amount): self
    {
        $this->balance -= $amount;

        return $this;
    }

    public function addTransaction(Transaction $transaction): self
    {
        if(!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): self
    {
        if($this->transactions->contains($transaction)) {
            $this->transactions->removeElement($transaction);
        }

        return $this;
    }

    #[Pure] public function getTransactions(): array
    {
        return $this->transactions->toArray();
    }

    public function getIncomes(): array
    {
        return $this->transactions->filter(function (Transaction $transaction) {
            return $transaction->getType() === TransactionInterface::INCOME;
        })->toArray();
    }

    public function getExpenses(): array
    {
        return $this->transactions->filter(function (Transaction $transaction) {
            return $transaction->getType() === TransactionInterface::EXPENSE;
        })->toArray();
    }

    public function getClosedAt(): CarbonInterface|DateTimeInterface
    {
        if($this->closedAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->closedAt->getTimestamp(), $this->closedAt->getTimezone());
        }

        return $this->closedAt;
    }

    public function setClosedAt(?DateTimeInterface $closedAt = null): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function close(?CarbonInterface $date = null): void
    {
        $this->closedAt = ($date) ?: CarbonImmutable::now();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function getValuableField(): string
    {
        return 'balance';
    }
}
