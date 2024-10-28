<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter;
use App\ApiPlatform\DiscriminatorFilter;
use App\Repository\CategoryRepository;
use App\Traits\TimestampableEntity;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Pure;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: "unique_category", columns: ["name", "type"])]
#[ORM\Index(columns: ["name"], name: "category_name_idx")]
#[ORM\InheritanceType("SINGLE_TABLE")]
#[ORM\DiscriminatorColumn(name: "type", type: "string")]
#[ORM\DiscriminatorMap([
    'expense' => ExpenseCategory::class,
    'income' => IncomeCategory::class,
])]
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'category:collection:read'],
        ],
    ],
    itemOperations: [
        'put' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'category:write'],
        ],
        'delete' => [
            'requirements' => ['id' => '\d+'],
        ],
    ],
    denormalizationContext: ['groups' => 'category:write'],
    order: ['name' => 'ASC'],
    paginationEnabled: false,
)]
#[ApiFilter(DiscriminatorFilter::class, arguments: [
    'types' => [
        'expense' => ExpenseCategory::class,
        'income' => IncomeCategory::class,
    ],
])]
#[ApiFilter(ExistsFilter::class, properties: ['root'])]
#[ApiFilter(BooleanFilter::class, properties: ['isAffectingProfit'])]
#[ApiFilter(DateFilter::class, properties: ['transactions.executedAt'])]
#[Serializer\Discriminator([
    'field' => 'type',
    'groups' => ['category:collection:read', 'category:tree:read'],
    'map' => [
        'expense' => ExpenseCategory::class,
        'income' => IncomeCategory::class,
    ],
    'disabled' => false,
])]
abstract class Category
{
    use TimestampableEntity;

    public const EXPENSE_CATEGORY_TYPE = 'expense';
    public const INCOME_CATEGORY_TYPE = 'income';

    public const CATEGORY_TRANSFER = 'Transfer';
    public const CATEGORY_DEBT = 'Debt';
    public const CATEGORY_TRANSFER_FEE = 'Transfer Fee';
    public const CATEGORY_GROCERIES = 'Groceries';

    public const EXPENSE_CATEGORY_ID_UNKNOWN = 17;
    public const INCOME_CATEGORY_ID_UNKNOWN = 39;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups([
        'account:item:read',
        'debt:collection:read',
        'category:collection:read',
        'category:tree:read',
        'transaction:collection:read',
        'transfer:collection:read'
    ])]
    #[Serializer\Groups([
        'category:collection:read',
        'category:tree:read',
        'account:item:read',
        'transaction:collection:read',
        'debt:collection:read',
        'transfer:collection:read'
    ])]
    private ?int $id;

    #[Gedmo\Timestampable(on: "create")]
    #[ORM\Column(type: "datetime", nullable: true)]
    #[Groups(['category:collection:read', 'category:tree:read'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    protected ?DateTimeInterface $createdAt;

    #[Gedmo\Timestampable(on: "update")]
    #[ORM\Column(type: "datetime", nullable: true)]
    protected ?DateTimeInterface $updatedAt;

    #[ORM\Column(type: "string", length: 255)]
    #[Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'category:collection:read',
        'category:tree:read',
        'category:write',
    ])]
    #[Serializer\Groups([
        'category:collection:read',
        'category:tree:read',
        'account:item:read',
        'transaction:collection:read',
        'debt:collection:read',
    ])]
    private ?string $name;

    #[ORM\Column(type: Types::BOOLEAN, nullable: false, options: ["default" => false])]
    #[Groups(['category:collection:read', 'category:tree:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    private bool $isTechnical;

    #[ORM\OneToMany(mappedBy: "parent", targetEntity: Category::class, cascade: ["remove"], fetch: "EXTRA_LAZY")]
    #[Groups(['category:tree:read'])]
    #[Serializer\Groups(['category:tree:read'])]
    private Collection $children;

    #[ORM\ManyToOne(targetEntity: Category::class, fetch: "EXTRA_LAZY", inversedBy: "children")]
    #[ORM\JoinColumn(name: "parent_id", referencedColumnName: "id")]
    #[Groups(['category:collection:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read'])]
    private ?Category $parent;

    #[ORM\ManyToOne(targetEntity: Category::class, fetch: "EXTRA_LAZY")]
    #[ORM\JoinColumn(name: "root_id", referencedColumnName: "id")]
    #[Groups(['category:collection:read'])]
    #[Serializer\Groups(['category:collection:read'])]
    private ?Category $root = null;

    #[ORM\OneToMany(mappedBy: "category", targetEntity: Transaction::class, cascade: ["remove"], fetch: "EXTRA_LAZY", orphanRemoval: true)]
    #[ORM\OrderBy(["executedAt" => "ASC"])]
    private Collection $transactions;

    #[ORM\Column(type: Types::BOOLEAN, nullable: false)]
    #[Groups(['category:collection:read', 'category:tree:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    private bool $isAffectingProfit = true;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    #[Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'category:collection:read',
        'category:tree:read',
        'category:write',
    ])]
    #[Serializer\Groups([
        'category:collection:read',
        'category:tree:read',
        'account:item:read',
        'transaction:collection:read',
        'debt:collection:read',
    ])]
    private ?string $icon;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    #[Groups([
        'transaction:collection:read',
        'account:item:read',
        'debt:collection:read',
        'category:collection:read',
        'category:tree:read',
        'category:write',
    ])]
    #[Serializer\Groups([
        'category:collection:read',
        'category:tree:read',
        'account:item:read',
        'transaction:collection:read',
        'debt:collection:read',
    ])]
    private ?string $color;

    #[Assert\Valid]
    #[ORM\ManyToMany(targetEntity: CategoryTag::class, cascade: ["persist"], inversedBy: "categories")]
    #[ORM\JoinTable(name: "categories_tags")]
    #[Groups(['category:collection:read', 'category:tree:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    private Collection $tags;

    #[Groups(['category:tree:read', 'account:item:read'])]
    #[Serializer\Groups(['category:tree:read', 'account:item:read'])]
    private float $value = 0;

    #[Groups(['category:tree:read', 'account:item:read'])]
    #[Serializer\Groups(['category:tree:read', 'account:item:read'])]
    private float $total = 0;

    #[Groups(['category:collection:read', 'category:tree:read'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    abstract public function getType(): string;

    #[Pure]
    public function __construct(string $name = null, bool $isTechnical = false)
    {
        $this->name = $name;
        $this->transactions = new ArrayCollection();
        $this->children = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->isTechnical = $isTechnical;
    }

    public function __toString(): string
    {
        return $this->name ?: 'New Category';
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isRoot(): bool
    {
        return $this->root === null;
    }

    public function getRoot(): ?Category
    {
        return $this->root;
    }

    public function setRoot(?Category $category): self
    {
        $this->root = $category;

        $children = $this->getChildren()->getIterator();
        /** @var Category $child */
        foreach ($children as $child) {
            $child->setRoot($category ?: $this);
        }

        return $this;
    }

    public function hasParent(): bool
    {
        return $this->parent !== null;
    }

    public function getParent(): ?Category
    {
        return $this->parent;
    }

    public function setParent(?Category $parent): self
    {
        $this->parent = $parent;

        if (!$parent) {
            $this->setRoot(null);
        } else {
            $this->setRoot($parent->isRoot() ? $parent : $parent->getRoot());
        }

        return $this;
    }

    public function getTransactionsCount(bool $withChildren = true): int
    {
        $result = $this->transactions->count();

        if (!$withChildren) {
            return $result;
        }

        foreach ($this->children as $child) {
            $result += $child->getTransactionsCount();
        }

        return $result;
    }

    public function getIsTechnical(): bool
    {
        return $this->isTechnical;
    }

    public function setIsTechnical(bool $isTechnical): self
    {
        $this->isTechnical = $isTechnical;

        return $this;
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

    public function getTransactions(bool $withDescendants = false): Collection
    {
        if (!$withDescendants) {
            return $this->transactions;
        }

        $transactions = $this->transactions->toArray();

        $this->children->map(static function (Category $child) use (&$transactions, $withDescendants) {
            $transactions = array_merge($transactions, $child->getTransactions($withDescendants)->toArray());
        });

        return new ArrayCollection($transactions);
    }

    public function addChildren(Category $category): self
    {
        if (!$this->children->contains($category)) {
            $this->children->add($category);
        }

        return $this;
    }

    public function removeChildren(Category $category): self
    {
        if ($this->children->contains($category)) {
            $this->children->removeElement($category);
        }

        return $this;
    }

    public function getIsAffectingProfit(): bool
    {
        return $this->isAffectingProfit;
    }

    public function setIsAffectingProfit(bool $isAffectingProfit): self
    {
        $this->isAffectingProfit = $isAffectingProfit;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getFirstDescendantsNames(): array
    {
        return array_map(static function (Category $category) {
            return $category->getName();
        }, $this->getChildren()->toArray());
    }

    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function isExpense(): bool
    {
        return $this->getType() === self::EXPENSE_CATEGORY_TYPE;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isChildOf(Category $possibleParent): bool
    {
        return $possibleParent->getDescendantsFlat()->contains($this);
    }

    public function getDescendantsFlat(bool $onlyNames = false): Collection
    {
        $result = [$this];

        $this->children->map(static function (Category $child) use (&$result) {
            $result = array_merge($result, $child->getDescendantsFlat()->toArray());
        });

        if ($onlyNames) {
            $result = array_map(static function (Category $category) {
                return $category->getName();
            }, $result);
        }

        return new ArrayCollection($result);
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
    }

    public function addTag(CategoryTag ...$tags): self
    {
        foreach ($tags as $tag) {
            if (!$this->tags->contains($tag)) {
                $this->tags->add($tag);
            }
        }

        return $this;
    }

    public function removeTag(CategoryTag $tag): self
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function setValue(float $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function setTotal(float $total): self
    {
        $this->total = $total;

        return $this;
    }
}
