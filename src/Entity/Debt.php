<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
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
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[Gedmo\SoftDeleteable(fieldName: 'closedAt', timeAware: false, hardDelete: false)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: DebtRepository::class)]
#[ApiResource(
    description: 'A debt owed to or by someone. Tracks balance via linked transactions. Soft-deletable via closedAt.',
    operations: [
        new GetCollection(
            description: 'List all debts (open and closed) for the authenticated user.',
            normalizationContext: ['groups' => 'debt:collection:read'],
        ),
        new Post(
            description: 'Create a new debt record. Link transactions to it to track payments.',
            normalizationContext: ['groups' => 'debt:collection:read'],
        ),
        new Put(
            description: 'Update debt details (debtor name, note, balance, closedAt).',
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => 'debt:collection:read'],
        ),
        new Delete(requirements: ['id' => '\d+']),
    ],
    denormalizationContext: ['groups' => 'debt:write'],
    order: ['updatedAt' => 'DESC'],
    paginationEnabled: false,
)]
class Debt implements OwnableInterface, ValuableInterface
{
    use OwnableValuableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['debt:collection:read', 'debt:item:read'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?int $id;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Groups(['debt:collection:read', 'debt:item:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?string $debtor;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::STRING, length: 3)]
    #[Groups(['debt:collection:read', 'debt:item:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?string $currency;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['debt:collection:read', 'debt:item:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    protected ?string $note;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
    #[Groups(['debt:collection:read', 'debt:item:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    #[Serializer\Type(Types::FLOAT)]
    private string $balance = '0.0';

    #[ORM\OneToMany(mappedBy: 'debt', targetEntity: Transaction::class, cascade: ['persist'], fetch: 'EXTRA_LAZY', orphanRemoval: false)]
    #[ORM\OrderBy(['executedAt' => 'DESC'])]
    #[Groups(['debt:item:read'])]
    #[Serializer\Groups(['debt:item:read'])]
    private Collection $transactions;

    #[ApiProperty(description: 'Debt balance converted to the user\'s base currency. Keyed by currency code.')]
    #[ORM\Column(type: Types::JSON, nullable: false)]
    #[Groups(['debt:collection:read', 'debt:item:read'])]
    #[Serializer\Groups(['debt:collection:read'])]
    protected ?array $convertedValues = [];

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['debt:collection:read', 'debt:item:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    protected ?DateTimeInterface $createdAt;

    #[ApiProperty(description: 'When set, the debt is considered settled/closed. Used for soft-delete filtering.')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['debt:collection:read', 'debt:item:read', 'debt:write'])]
    #[Serializer\Groups(['debt:collection:read'])]
    private ?DateTimeInterface $closedAt;

    public function __construct()
    {
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
        return (float) $this->balance;
    }

    public function setBalance(string|float|int $balance): self
    {
        $this->balance = (string) $balance;

        return $this;
    }

    public function increaseBalance(float $amount): self
    {
        return $this->setBalance((float) $this->balance + $amount);
    }

    public function decreaseBalance(float $amount): self
    {
        return $this->setBalance((float) $this->balance - $amount);
    }

    public function addTransaction(Transaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): self
    {
        if ($this->transactions->contains($transaction)) {
            $this->transactions->removeElement($transaction);
        }

        return $this;
    }

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
        return $this->transactions->filter(static function (Transaction $transaction) {
            return Transaction::INCOME === $transaction->getType();
        });
    }

    public function getExpenses(): Collection
    {
        return $this->transactions->filter(static function (Transaction $transaction) {
            return Transaction::EXPENSE === $transaction->getType();
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
        $this->closedAt = $date ?? CarbonImmutable::now();
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
