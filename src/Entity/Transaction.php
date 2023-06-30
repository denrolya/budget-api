<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use App\ApiPlatform\CategoryDeepSearchFilter;
use App\ApiPlatform\DiscriminatorFilter;
use App\DTO\MonobankResponse;
use App\Traits\ExecutableEntity;
use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use JMS\Serializer\Annotation as Serializer;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\TransactionRepository")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"expense" = "App\Entity\Expense", "income" = "App\Entity\Income"})
 */
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'transaction:collection:read'],
        ],
        'money_flow' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/money-flow',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Money Flow',
                'description' => 'Generates money flow statistics within given date range and grouped by interval(optional)',
            ],
        ],
        'income_expense' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/income-expense',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Incomes & Expenses',
                'description' => 'Incomes & expenses ',
            ],
        ],
        'sum' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/sum',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Sum Transactions',
                'description' => '',
            ],
        ],
        'avg' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/avg',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Average amount of transactions',
                'description' => '',
            ],
        ],
        'min_max' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/min-max',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Minimum & maximum by interval',
                'description' => '',
            ],
        ],
        'average_transaction_weekly' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/avg-weekly',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Average transaction weekly',
                'description' => '',
            ],
        ],
        'categories_timeline' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/categories-timeline',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Categories Timeline',
                'description' => '',
            ],
        ],
        'top_value_category' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/top-value-category',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Top value category',
                'description' => 'Category that has the biggest cumulative value',
            ],
        ],
        'account_distribution' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/account-distribution',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Account distribution',
                'description' => '',
            ],
        ],
        'utility_costs' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/utilities',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'Utility costs',
                'description' => '',
            ],
        ],
        'by_weekdays' => [
            'method' => 'GET',
            'path' => '/transactions/statistics/by-weekdays',
            'pagination_enabled' => false,
            'openapi_context' => [
                'summary' => 'By Weekdays',
                'description' => '',
            ],
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
#[ApiFilter(CategoryDeepSearchFilter::class)]
#[ApiFilter(DiscriminatorFilter::class, arguments: [
    'types' => [
        'expense' => Expense::class,
        'income' => Income::class,
    ],
])]
#[Serializer\Discriminator([
    'field' => 'type',
    'groups' => ['transaction:collection:read'],
    'map' => [
        'expense' => Expense::class,
        'income' => Income::class,
    ],
    'disabled' => false,
])]
abstract class Transaction implements TransactionInterface, OwnableInterface, ExecutableInterface
{
    use TimestampableEntity, OwnableValuableEntity, ExecutableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'transfer:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected ?int $id;

    /**
     * @Assert\NotBlank()
     *
     * @ORM\ManyToOne(targetEntity=Account::class, inversedBy="transactions")
     * @ORM\JoinColumn(name="account_id", referencedColumnName="id", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read', 'debt:collection:read', 'transfer:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected ?Account $account;

    /**
     * @var float
     * @Assert\NotBlank()
     * @Assert\Type("numeric")
     * @Assert\GreaterThan(value="0")
     *
     * @ORM\Column(type="decimal", precision=50, scale=30)
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read', 'debt:collection:read', 'transfer:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected float $amount = 0;

    /**
     * @ORM\Column(type="json", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected ?array $convertedValues = [];

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read', 'debt:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected ?string $note;

    /**
     * @Assert\NotBlank()
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read', 'debt:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected ?DateTimeInterface $executedAt;

    /**
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTimeInterface $createdAt;

    /**
     * @Assert\NotBlank()
     *
     * @ORM\ManyToOne(targetEntity=Category::class, inversedBy="transactions", cascade={"persist"})
     * @ORM\JoinColumn(name="category_id", referencedColumnName="id", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read', 'debt:collection:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    protected ?Category $category;

    /**
     * @ORM\Column(type="boolean", nullable=false)
     */
    #[Groups(['transaction:collection:read', 'transaction:write', 'account:item:read'])]
    #[Serializer\Groups(['transaction:collection:read'])]
    private bool $isDraft;

    /**
     * @ORM\ManyToOne(targetEntity=Debt::class, inversedBy="transactions")
     * @ORM\JoinColumn(name="debt_id", referencedColumnName="id")
     */
    #[Groups(['transaction:write'])]
    private ?Debt $debt = null;

    #[Groups(['transaction:collection:read', 'debt:collection:read', 'account:item:read'])]
    abstract public function getType(): string;

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
        return $this->category->isRoot() ? $this->category : $this->category->getRoot();
    }

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

    public function getDebt(): ?Debt
    {
        return $this->debt;
    }

    public function setDebt(?Debt $debt): self
    {
        $this->debt = $debt;

        return $this;
    }
}
