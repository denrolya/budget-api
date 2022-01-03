<?php

namespace App\Entity;

use App\Traits\ExecutableEntity;
use App\Traits\OwnableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @Gedmo\SoftDeleteable(fieldName="canceledAt", timeAware=false, hardDelete=false)
 *
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\TransferRepository")
 */
class Transfer implements OwnableInterface
{
    use TimestampableEntity, OwnableEntity, ExecutableEntity;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\ManyToOne(targetEntity="Account")
     * @ORM\JoinColumn(name="from_id", referencedColumnName="id")
     */
    private ?Account $from;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\ManyToOne(targetEntity="Account")
     * @ORM\JoinColumn(name="to_id", referencedColumnName="id")
     */
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
     * @Groups({"transaction_list", "account:details", "debt_list", "transfer_list"})
     *
     * @ORM\Column(type="decimal", precision=15, scale=5)
     */
    private float $amount;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\Column(type="decimal", precision=15, scale=5, nullable=false)
     */
    private float $rate = 0;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5, nullable=false)
     */
    private float $fee = 0;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\OneToOne(targetEntity="Expense", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\JoinColumn(name="fee_expense_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private ?Expense $feeExpense;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $note;

    /**
     * @Groups({"transfer_list"})
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTimeInterface $executedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $canceledAt;

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

    public function getFeeExpense(): Expense
    {
        return $this->feeExpense;
    }

    public function setFeeExpense(Expense $expense): self
    {
        $this->feeExpense = $expense;

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

    public function getCanceledAt(): CarbonInterface|DateTimeInterface
    {
        if($this->canceledAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->canceledAt->getTimestamp(), $this->canceledAt->getTimezone());
        }

        return $this->canceledAt;
    }

    public function setCanceledAt(?DateTimeInterface $canceledAt): self
    {
        $this->canceledAt = $canceledAt;

        return $this;
    }

    public function cancel(): self
    {
        return $this->setCanceledAt(CarbonImmutable::now());
    }
}
