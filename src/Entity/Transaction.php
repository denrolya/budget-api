<?php

namespace App\Entity;

use App\Traits\ExecutableEntity;
use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @Gedmo\SoftDeleteable(fieldName="canceledAt", timeAware=false, hardDelete=false)
 *
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\TransactionRepository")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"expense" = "App\Entity\Expense", "income" = "App\Entity\Income"})
 */
abstract class Transaction implements TransactionInterface, OwnableInterface, ExecutableInterface
{
    use TimestampableEntity, OwnableValuableEntity, ExecutableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'transfer:list'])]
    protected ?int $id;

    /**
     * @ORM\ManyToOne(targetEntity="Account", inversedBy="transactions")
     * @ORM\JoinColumn(name="account_id", referencedColumnName="id", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'transfer:list'])]
    protected Account $account;

    /**
     * @ORM\Column(type="decimal", precision=15, scale=5)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'transfer:list'])]
    protected float $amount = 0;

    /**
     * TODO: getValues
     *
     * @ORM\Column(type="json", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    protected ?array $convertedValues = [];

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    protected ?string $note;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    protected ?DateTimeInterface $executedAt;

    /**
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTimeInterface $createdAt;

    /**
     * @ORM\ManyToOne(targetEntity="Category", inversedBy="transactions", cascade={"persist"})
     * @ORM\JoinColumn(name="category_id", referencedColumnName="id", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    private ?Category $category;

    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $canceledAt = null;

    /**
     * @ORM\Column(type="boolean", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'account:item:read'])]
    private bool $isDraft;

    #[Pure]
    public function __construct(bool $isDraft = false)
    {
        $this->isDraft = $isDraft;
    }

    public function __toString(): string
    {
        return (string)$this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    #[Pure]
    public function getRootCategory(): Category
    {
        return $this->category->getRoot();
    }

    #[Pure]
    public function getCurrency(): string
    {
        return $this->getAccount()->getCurrency();
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): TransactionInterface
    {
        $this->account = $account;

        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): TransactionInterface
    {
        $this->amount = $amount;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): TransactionInterface
    {
        $this->note = $note;

        return $this;
    }

    public function getCanceledAt(): CarbonInterface|DateTimeInterface|null
    {
        if($this->canceledAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->canceledAt->getTimestamp(), $this->canceledAt->getTimezone());
        }

        return $this->canceledAt;
    }

    public function setCanceledAt(?DateTimeInterface $canceledAt): TransactionInterface
    {
        $this->canceledAt = $canceledAt;

        return $this;
    }

    public function cancel(): TransactionInterface
    {
        return $this->setCanceledAt(CarbonImmutable::now());
    }

    public function isExpense(): bool
    {
        return $this->getType() === self::EXPENSE;
    }

    public function isIncome(): bool
    {
        return $this->getType() === self::INCOME;
    }

    #[Pure]
    public function isDebt(): bool
    {
        return $this->getCategory()->getName() === Category::CATEGORY_DEBT;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): TransactionInterface
    {
        $this->category = $category;
        $this->category->updateTimestamps();

        return $this;
    }

    public function getValuableField(): string
    {
        return 'amount';
    }

    public function getIsDraft(): bool
    {
        return $this->isDraft;
    }

    public function setIsDraft(bool $isDraft): self
    {
        $this->isDraft = $isDraft;

        return $this;
    }
}
