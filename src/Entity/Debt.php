<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Annotation\ApiSubresource;
use App\Repository\DebtRepository;
use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Validator\Constraints as Assert;

#[Gedmo\SoftDeleteable(fieldName: 'closedAt', timeAware: false, hardDelete: false)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: DebtRepository::class)]
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'debt:collection:read'],
        ],
        'post' => [
            'normalization_context' => ['groups' => 'debt:collection:read'],
        ],
    ],
    itemOperations: [
        'get' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'debt:item:read'],
        ],
        'put' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'debt:collection:read'],
        ],
        'delete' => [
            'requirements' => ['id' => '\d+'],
        ],
    ],
    denormalizationContext: ['groups' => 'debt:write'],
    order: ['updatedAt' => 'DESC'],
    paginationEnabled: false
)]
class Debt implements OwnableInterface, ValuableInterface
{
    use TimestampableEntity, OwnableValuableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['debt:collection:read'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?int $id;

    #[ORM\Column(type: 'json', nullable: false)]
    #[Groups(['debt:collection:read'])]
    #[Serializer\Groups(['debt:collection:read'])]
    protected ?array $convertedValues = [];

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['debt:collection:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    protected ?DateTimeInterface $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['debt:collection:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    protected ?string $note;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['debt:collection:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?string $debtor;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 3)]
    #[Groups(['debt:collection:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?string $currency;

    #[ORM\Column(type: 'string', length: 100)]
    #[Groups(['debt:collection:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    #[Serializer\Type('float')]
    private string $balance = '0.0';

    // TODO: Remove transactions from collection:read
    #[ORM\OneToMany(mappedBy: 'debt', targetEntity: Transaction::class)]
    #[ORM\OrderBy(['executedAt' => 'DESC'])]
    #[Groups(['debt:collection:read'])]
    #[ApiSubresource]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?Collection $transactions;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['debt:collection:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
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
        return (float)$this->balance;
    }

    public function setBalance(string $balance): self
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

    #[Pure]
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function getTransactionsCount(): int
    {
        return $this->transactions->count();
    }

    public function getIncomes(): Collection
    {
        return $this->transactions->filter(function (Transaction $transaction) {
            return $transaction->getType() === Transaction::INCOME;
        });
    }

    public function getExpenses(): Collection
    {
        return $this->transactions->filter(function (Transaction $transaction) {
            return $transaction->getType() === Transaction::EXPENSE;
        });
    }

    public function getClosedAt(): ?CarbonImmutable
    {
        return ($this->closedAt instanceof DateTimeInterface) ? CarbonImmutable::instance($this->closedAt) : null;
    }

    public function setClosedAt(?DateTimeInterface $closedAt): self
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
