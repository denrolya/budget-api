<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use App\ApiPlatform\WithDeletedFilter;
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

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: TransferRepository::class)]
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'transfer:collection:read'],
        ],
        'post',
    ],
    itemOperations: [
        'get' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'transfer:item:read'],
        ],
        'put' => [
            'requirements' => ['id' => '\d+'],
        ],
        'delete' => [
            'requirements' => ['id' => '\d+'],
        ],
    ],
    denormalizationContext: ['groups' => 'transfer:write'],
    order: ['executedAt' => 'DESC'],
    paginationClientItemsPerPage: true,
    paginationItemsPerPage: 20,
)]
#[ApiFilter(DateFilter::class, properties: ['executedAt'])]
#[ApiFilter(SearchFilter::class, properties: ['note' => 'ipartial', 'account' => 'exact', 'category' => 'exact'])]
#[ApiFilter(RangeFilter::class, properties: ['amount'])]
#[ApiFilter(WithDeletedFilter::class)]
class Transfer implements OwnableInterface
{
    use TimestampableEntity, OwnableEntity, ExecutableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['transfer:collection:read'])]
    private ?int $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?Account $from;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?Account $to;

    #[Assert\Type("numeric")]
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 30, nullable: false)]
    #[Groups(['account:item:read', 'debt:collection:read', 'transfer:collection:read', 'transfer:write'])]
    private string $amount = '0';

    #[Assert\Type("numeric")]
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 30, nullable: false)]
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private string $rate = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 30, nullable: false)]
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private string $fee = '0';

    #[Groups(['transfer:write'])]
    private ?Account $feeAccount = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?string $note;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    protected ?DateTimeInterface $executedAt;

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

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRate(): float
    {
        return (float)$this->rate;
    }

    public function setRate(float $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getFee(): float
    {
        return (float)$this->fee;
    }

    public function setFee(float $fee): self
    {
        $this->fee = $fee;

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

    public function addTransaction(TransactionInterface $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions[] = $transaction;
            $transaction->setTransfer($this);
        }

        return $this;
    }

    public function removeTransaction(TransactionInterface $transaction): self
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
            fn(TransactionInterface $transaction) => $transaction->isExpense() && $transaction->getCategory()->getName(
                ) === Category::CATEGORY_TRANSFER_FEE
        )->first();

        return $transaction ?: null;
    }

    public function getFromExpense(): ?Expense
    {
        $transaction = $this->transactions->filter(
            fn(TransactionInterface $transaction) => $transaction->isExpense() && $transaction->getCategory()->getName(
                ) === Category::CATEGORY_TRANSFER
        )->first();

        return $transaction ?: null;
    }

    public function getToIncome(): ?Income
    {
        $transaction = $this->transactions->filter(
            fn(TransactionInterface $transaction) => $transaction->isIncome() && $transaction->getCategory()->getName(
                ) === Category::CATEGORY_TRANSFER
        )->first();

        return $transaction ?: null;
    }
}
