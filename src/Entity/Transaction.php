<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use App\ApiPlatform\CategoryDeepSearchFilter;
use App\ApiPlatform\DiscriminatorFilter;
use App\DTO\MonobankResponse;
use App\Repository\TransactionRepository;
use App\Traits\ExecutableEntity;
use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\InheritanceType("SINGLE_TABLE")]
#[ORM\DiscriminatorColumn(name: "type", type: "string")]
#[ORM\DiscriminatorMap(["expense" => Expense::class, "income" => Income::class])]
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'transaction:collection:read'],
        ],
        'get_monobank' => [
            'method' => 'GET',
            'path' => '/monobank/transactions',
            'status' => 200,
        ],
        'post_monobank' => [
            'method' => 'POST',
            'path' => '/monobank/transactions',
            'input' => MonobankResponse::class,
            'status' => 200,
            'denormalization_context' => ['groups' => 'transaction:write'],
            'normalization_context' => ['groups' => 'transaction:collection:read'],
        ],
    ],
    itemOperations: [
        'get' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'transaction:item:read'],
        ],
        'put' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'transaction:collection:read'],
        ],
        'delete' => [
            'requirements' => ['id' => '\d+'],
        ],
    ],
    denormalizationContext: ['groups' => 'transaction:write'],
    order: ['executedAt' => 'DESC'],
    paginationClientItemsPerPage: true,
    paginationItemsPerPage: 20,
)]
#[ApiFilter(DateFilter::class, properties: ['executedAt'])]
#[ApiFilter(SearchFilter::class, properties: ['account.id' => 'exact', 'category.id' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isDraft', 'category.isAffectingProfit'])]
#[ApiFilter(RangeFilter::class, properties: ['amount'])]
#[ApiFilter(CategoryDeepSearchFilter::class)]
#[ApiFilter(DiscriminatorFilter::class, arguments: [
    'types' => [
        'expense' => Expense::class,
        'income' => Income::class,
    ],
])]
#[Serializer\Discriminator([
    'field' => 'type',
    'groups' => ['transaction:collection:read', 'account:item:read', 'debt:collection:read'],
    'map' => [
        'expense' => Expense::class,
        'income' => Income::class,
    ],
    'disabled' => false,
])]
abstract class Transaction implements OwnableInterface
{
    use TimestampableEntity, OwnableValuableEntity, ExecutableEntity;

    public const INCOME = 'income';
    public const REVENUE = 'revenue';
    public const EXPENSE = 'expense';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'transfer:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    protected ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: "transactions")]
    #[ORM\JoinColumn(name: "account_id", referencedColumnName: "id", nullable: false)]
    #[Groups([
        'transaction:collection:read',
        'transaction:write',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    protected Account $account;

    #[Assert\NotBlank]
    #[Assert\Type('numeric')]
    #[Assert\GreaterThan(value: "0")]
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Groups([
        'transaction:collection:read',
        'transaction:write',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Type(Types::FLOAT)]
    protected string $amount = '0.0';

    #[ORM\Column(type: Types::JSON, nullable: false)]
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'transfer:collection:read'])]
    #[Serializer\Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    protected ?array $convertedValues = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups([
        'transaction:collection:read',
        'transaction:write',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    protected ?string $note;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups([
        'transaction:collection:read',
        'transaction:write',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    protected ?DateTimeInterface $executedAt;

    #[Gedmo\Timestampable(on: "create")]
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?DateTimeInterface $createdAt;

    #[Assert\NotBlank]
    #[ORM\ManyToOne(targetEntity: Category::class, cascade: ["persist"], inversedBy: "transactions")]
    #[ORM\JoinColumn(name: "category_id", referencedColumnName: "id", nullable: false)]
    #[Groups([
        'transaction:collection:read',
        'transaction:write',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    protected Category $category;

    #[ORM\Column(type: Types::BOOLEAN, nullable: false)]
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    private bool $isDraft;

    #[ORM\ManyToOne(targetEntity: Debt::class, cascade: ["persist"], inversedBy: "transactions")]
    #[Groups(['transaction:write'])]
    private ?Debt $debt = null;

    #[Serializer\Groups(['transaction:collection:read'])]
    #[ORM\ManyToOne(targetEntity: Transfer::class, cascade: ["remove"], inversedBy: "transactions")]
    private ?Transfer $transfer = null;

    #[Groups(['transaction:collection:read', 'debt:collection:read', 'account:item:read', 'transfer:collection:read'])]
    abstract public function getType(): string;

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

    public function getRootCategory(): Category
    {
        return $this->category->isRoot() ? $this->category : $this->category->getRoot();
    }

    public function getCurrency(): string
    {
        return $this->getAccount()->getCurrency();
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getAmount(): float
    {
        return (float)$this->amount;
    }

    public function setAmount(string|float $amount): self
    {
        $this->amount = (string)$amount;

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

    public function isExpense(): bool
    {
        return $this->getType() === self::EXPENSE;
    }

    public function isIncome(): bool
    {
        return $this->getType() === self::INCOME;
    }

    public function isDebt(): bool
    {
        return $this->getCategory()->getName() === Category::CATEGORY_DEBT;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
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

    public function getDebt(): ?Debt
    {
        return $this->debt;
    }

    public function setDebt(?Debt $debt): self
    {
        $this->debt = $debt;

        return $this;
    }

    public function getTransfer(): ?Transfer
    {
        return $this->transfer;
    }

    public function setTransfer(?Transfer $transfer): self
    {
        $this->transfer = $transfer;

        return $this;
    }
}
