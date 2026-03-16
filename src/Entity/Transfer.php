<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\ApiPlatform\TransferAccountsFilter;
use App\ApiPlatform\WithDeletedFilter;
use App\DataPersister\TransferDataPersister;
use App\Repository\TransferRepository;
use App\Traits\ExecutableEntity;
use App\Traits\OwnableEntity;
use App\Traits\TimestampableEntity;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use JMS\Serializer\Annotation as Serializer;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: TransferRepository::class)]
#[ApiResource(
    description: 'A money transfer between two accounts. Automatically creates paired expense/income transactions and an optional fee expense.',
    operations: [
        new GetCollection(
            description: 'List all transfers with their linked transactions, ordered by execution date.',
            normalizationContext: ['groups' => 'transfer:collection:read'],
        ),
        new Post(
            description: 'Create a transfer between two accounts. Specify amount, rate (for cross-currency), and optional fee.',
            processor: TransferDataPersister::class,
        ),
        new Get(requirements: ['id' => '\d+'], normalizationContext: ['groups' => 'transfer:item:read']),
        new Put(
            description: 'Update a transfer. Recalculates linked transactions and account balances.',
            requirements: ['id' => '\d+'],
            processor: TransferDataPersister::class,
        ),
        new Delete(
            description: 'Delete a transfer and its linked transactions, reversing balance changes.',
            requirements: ['id' => '\d+'],
        ),
    ],
    denormalizationContext: ['groups' => 'transfer:write'],
    order: ['executedAt' => 'DESC'],
    paginationClientItemsPerPage: true,
    paginationItemsPerPage: 20,
)]
#[ApiFilter(DateFilter::class, properties: ['executedAt'])]
#[ApiFilter(SearchFilter::class, properties: ['note' => 'ipartial', 'category' => 'exact'])]
#[ApiFilter(RangeFilter::class, properties: ['amount'])]
#[ApiFilter(WithDeletedFilter::class)]
#[ApiFilter(TransferAccountsFilter::class)]
class Transfer implements OwnableInterface
{
    use TimestampableEntity, OwnableEntity, ExecutableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transaction:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read', 'transfer:collection:read'])]
    private ?int $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    private ?Account $from;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    private ?Account $to;

    #[Assert\Type("numeric")]
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 30, nullable: false)]
    #[Groups(['debt:collection:read', 'transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    private string $amount = '0';

    #[ApiProperty(description: 'Exchange rate applied when transferring between accounts with different currencies. Set to 1 for same-currency transfers.')]
    #[Assert\Type("numeric")]
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 30, nullable: false)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    private string $rate = '0';

    #[ApiProperty(description: 'Transfer fee amount in the source account currency. Creates a separate fee expense transaction if non-zero.')]
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 30, nullable: false)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    private string $fee = '0';

    #[ApiProperty(description: 'Optional account to charge the fee to. If null, the fee is charged to the source (from) account.')]
    #[Groups(['transfer:write'])]
    private ?Account $feeAccount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    private ?string $note;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['transfer:collection:read', 'transfer:item:read', 'transfer:write'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    protected ?DateTimeInterface $executedAt;

    #[Groups(['transfer:collection:read', 'transfer:item:read'])]
    #[Serializer\Groups(['transfer:collection:read'])]
    #[ORM\OneToMany(mappedBy: 'transfer', targetEntity: Transaction::class, cascade: [
        "persist",
        "remove",
    ], orphanRemoval: true)]
    private Collection $transactions;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFrom(): ?Account
    {
        return $this->from;
    }

    public function setFrom(Account $fromAccount): self
    {
        $this->from = $fromAccount;

        return $this;
    }

    public function getTo(): ?Account
    {
        return $this->to;
    }

    public function setTo(Account $toAccount): self
    {
        $this->to = $toAccount;

        return $this;
    }

    public function getAmount(): float
    {
        return (float)$this->amount;
    }

    public function setAmount(string|float|int $amount): self
    {
        $this->amount = (string)$amount;

        return $this;
    }

    public function getRate(): float
    {
        return (float)$this->rate;
    }

    public function setRate(string|float|int $rate): self
    {
        $this->rate = (string)$rate;

        return $this;
    }

    public function getFee(): float
    {
        return (float)$this->fee;
    }

    public function setFee(string|float|int $fee): self
    {
        $this->fee = (string)$fee;

        return $this;
    }

    public function getFeeAccount(): ?Account
    {
        return $this->feeAccount;
    }

    public function setFeeAccount(?Account $account): self
    {
        $this->feeAccount = $account;

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

    public function addTransaction(Transaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions[] = $transaction;
            $transaction->setTransfer($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): self
    {
        if ($this->transactions->contains($transaction)) {
            $this->transactions->removeElement($transaction);
            // set the owning side to null (unless already changed)
            if ($transaction->getTransfer() === $this) {
                $transaction->setTransfer(null);
            }
        }

        return $this;
    }

    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function getFeeExpense(): ?Expense
    {
        $transaction = $this->transactions->filter(
            fn(Transaction $transaction) => $transaction->isExpense() && $transaction->getCategory()->getName(
                ) === Category::CATEGORY_TRANSFER_FEE
        )->first();

        return $transaction !== false ? $transaction : null;
    }

    public function getFromExpense(): ?Expense
    {
        $transaction = $this->transactions->filter(
            fn(Transaction $transaction) => $transaction->isExpense() && $transaction->getCategory()->getName(
                ) === Category::CATEGORY_TRANSFER
        )->first();

        return $transaction !== false ? $transaction : null;
    }

    public function getToIncome(): ?Income
    {
        $transaction = $this->transactions->filter(
            fn(Transaction $transaction) => $transaction->isIncome() && $transaction->getCategory()->getName(
                ) === Category::CATEGORY_TRANSFER
        )->first();

        return $transaction !== false ? $transaction : null;
    }
}
