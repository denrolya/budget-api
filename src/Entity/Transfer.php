<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use App\ApiPlatform\WithDeletedFilter;
use App\Traits\ExecutableEntity;
use App\Traits\OwnableEntity;
use App\Traits\TimestampableEntity;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\TransferRepository")
 */
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'transfer:collection:read'],
        ],
        'post'
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
final class Transfer implements OwnableInterface
{
    use TimestampableEntity, OwnableEntity, ExecutableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    #[Groups(['transfer:collection:read'])]
    private ?int $id;

    /**
     * @ORM\ManyToOne(targetEntity="Account")
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?Account $from;

    /**
     * @ORM\ManyToOne(targetEntity="Account")
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?Account $to;

    /**
     * @ORM\OneToOne(targetEntity="Expense", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\JoinColumn(name="expense_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private ?Expense $fromExpense;

    /**
     * @ORM\OneToOne(targetEntity="Income", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\JoinColumn(name="income_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private ?Income $toIncome;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5)
     */
    #[Groups(['account:item:read', 'debt:collection:read', 'transfer:collection:read', 'transfer:write'])]
    private float $amount;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5, nullable=false)
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private float $rate = 0;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5, nullable=false)
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private float $fee = 0;

    /**
     * @ORM\OneToOne(targetEntity="Expense", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\JoinColumn(onDelete="CASCADE")
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?Expense $feeExpense;

    #[Groups(['transfer:write'])]
    private ?Account $feeAccount;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    private ?string $note;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['transfer:collection:read', 'transfer:write'])]
    protected ?DateTimeInterface $executedAt;

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

    public function getFromExpense(): ?Expense
    {
        return $this->fromExpense;
    }

    public function setFromExpense(Expense $fromExpense): self
    {
        $this->fromExpense = $fromExpense;

        return $this;
    }

    public function getToIncome(): ?Income
    {
        return $this->toIncome;
    }

    public function setToIncome(Income $toIncome): self
    {
        $this->toIncome = $toIncome;

        return $this;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRate(): float
    {
        return $this->rate;
    }

    public function setRate(float $rate): self
    {
        $this->rate = $rate;

        return $this;
    }

    public function getFee(): float
    {
        return $this->fee;
    }

    public function setFee(float $fee): self
    {
        $this->fee = $fee;

        return $this;
    }

    public function getFeeExpense(): ?Expense
    {
        return $this->feeExpense;
    }

    public function setFeeExpense(?Expense $expense): self
    {
        $this->feeExpense = $expense;

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
}
