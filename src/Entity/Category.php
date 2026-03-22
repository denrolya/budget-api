<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\ApiPlatform\DiscriminatorFilter;
use App\Repository\CategoryRepository;
use App\Traits\OwnableEntity;
use App\Traits\TimestampableEntity;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'unique_category', columns: ['name', 'type', 'owner_id'])]
#[ORM\Index(columns: ['name'], name: 'category_name_idx')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string')]
#[ORM\DiscriminatorMap([
    'expense' => ExpenseCategory::class,
    'income' => IncomeCategory::class,
])]
#[ApiResource(
    description: 'A transaction category (expense or income). Supports hierarchical nesting via parent/root/children. Uses single-table inheritance.',
    operations: [
        new GetCollection(
            description: 'List all categories (flat). Use exists[root] filter to get only root or only child categories.',
            normalizationContext: ['groups' => 'category:collection:read'],
        ),
        new Put(
            description: 'Update category name, parent, or isAffectingProfit flag.',
            requirements: ['id' => '\d+'],
            normalizationContext: ['groups' => 'category:write'],
            security: 'object.getOwner() == user',
        ),
        new Delete(
            description: 'Delete a category and all its transactions (cascade).',
            requirements: ['id' => '\d+'],
            security: 'object.getOwner() == user',
        ),
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
abstract class Category implements OwnableInterface
{
    use OwnableEntity;
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
        'debt:collection:read',
        'category:collection:read',
        'category:tree:read',
        'transaction:collection:read',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups([
        'category:collection:read',
        'category:tree:read',
        'transaction:collection:read',
        'debt:collection:read',
        'transfer:collection:read',
    ])]
    private ?int $id;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['category:collection:read', 'category:tree:read'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    protected ?DateTimeInterface $createdAt;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    protected ?DateTimeInterface $updatedAt;

    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 255)]
    #[Groups([
        'transaction:collection:read',
        'debt:collection:read',
        'category:collection:read',
        'category:tree:read',
        'category:write',
        'transfer:collection:read',
    ])]
    #[Serializer\Groups([
        'category:collection:read',
        'category:tree:read',
        'transaction:collection:read',
        'debt:collection:read',
    ])]
    private ?string $name;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class, cascade: ['remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    #[Groups(['category:tree:read'])]
    #[Serializer\Groups(['category:tree:read'])]
    private Collection $children;

    #[ORM\ManyToOne(targetEntity: self::class, fetch: 'EXTRA_LAZY', inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id')]
    #[Groups(['category:collection:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read'])]
    private ?Category $parent;

    #[ORM\ManyToOne(targetEntity: self::class, fetch: 'EXTRA_LAZY')]
    #[ORM\JoinColumn(name: 'root_id', referencedColumnName: 'id')]
    #[Groups(['category:collection:read'])]
    #[Serializer\Groups(['category:collection:read'])]
    private ?Category $root = null;

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Transaction::class, cascade: ['remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    #[ORM\OrderBy(['executedAt' => 'ASC'])]
    private Collection $transactions;

    #[ApiProperty(description: 'When true, transactions in this category count toward profit/loss calculations. Set to false for transfers, debt repayments, etc.')]
    #[ORM\Column(type: Types::BOOLEAN, nullable: false)]
    #[Groups(['category:collection:read', 'category:tree:read', 'category:write'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    private bool $isAffectingProfit = true;

    #[ApiProperty(description: 'Sum of transaction amounts directly in this category (excluding children). Computed at read time for tree views.')]
    #[Groups(['category:tree:read'])]
    #[Serializer\Groups(['category:tree:read'])]
    private float $value = 0;

    #[ApiProperty(description: 'Sum of transaction amounts in this category and all descendants. Computed at read time for tree views.')]
    #[Groups(['category:tree:read'])]
    #[Serializer\Groups(['category:tree:read'])]
    private float $total = 0;

    #[Groups(['category:collection:read', 'category:tree:read'])]
    #[Serializer\Groups(['category:collection:read', 'category:tree:read'])]
    abstract public function getType(): string;

    public function __construct(?string $name = null)
    {
        $this->name = $name;
        $this->transactions = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name ?? 'New Category';
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
        return null === $this->root;
    }

    public function getRoot(): ?self
    {
        return $this->root;
    }

    public function setRoot(?self $category): self
    {
        $this->root = $category;

        $children = $this->getChildren()->getIterator();
        /** @var Category $child */
        foreach ($children as $child) {
            $child->setRoot($category ?? $this);
        }

        return $this;
    }

    public function hasParent(): bool
    {
        return null !== $this->parent;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
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

        /** @var Category $child */
        foreach ($this->children as $child) {
            $result += $child->getTransactionsCount();
        }

        return $result;
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

    public function addChildren(self $category): self
    {
        if (!$this->children->contains($category)) {
            $this->children->add($category);
        }

        return $this;
    }

    public function removeChildren(self $category): self
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

    /**
     * @return Collection<int, Category>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function isExpense(): bool
    {
        return self::EXPENSE_CATEGORY_TYPE === $this->getType();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isChildOf(self $possibleParent): bool
    {
        return $possibleParent->getDescendantsFlat()->contains($this);
    }

    public function getDescendantsFlat(bool $onlyNames = false): Collection
    {
        /** @var Category[] $result */
        $result = [$this];

        foreach ($this->children as $child) {
            $result = array_merge($result, $child->getDescendantsFlat()->toArray());
        }

        if ($onlyNames) {
            $names = [];
            foreach ($result as $category) {
                \assert($category instanceof Category);
                $names[] = $category->getName();
            }

            return new ArrayCollection($names);
        }

        return new ArrayCollection($result);
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
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
