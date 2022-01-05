<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Gedmo\SoftDeleteable(fieldName="removedAt", timeAware=false, hardDelete=false)
 *
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\CategoryRepository")
 * @ORM\Table(
 *     uniqueConstraints={@ORM\UniqueConstraint(name="unique_category", columns={"name", "type"})},
 *     indexes={@ORM\Index(name="category_name_idx", columns={"name"})}),
 * )
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"expense" = ExpenseCategory::class, "income" = IncomeCategory::class})
 */
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'category:collection:read'],
        ],
    ],
    itemOperations: [
        'put' => [
            'normalization_context' => ['groups' => 'category:write'],
        ],
        'delete' => [
            'requirements' => ['id' => '\d+'],
        ],
    ],
    denormalizationContext: ['groups' => 'category:write'],
)]
abstract class Category
{
    use TimestampableEntity;

    public const EXPENSE_CATEGORY_TYPE = 'expense';
    public const INCOME_CATEGORY_TYPE = 'income';

    public const CATEGORY_TRANSFER = 'Transfer';
    public const CATEGORY_DEBT = 'Debt';
    public const CATEGORY_TRANSFER_FEE = 'Transfer Fee';
    public const CATEGORY_GROCERIES = 'Groceries';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    #[Groups(['account:item:read', 'debt:collection:read', 'category:collection:read', 'category:tree'])]
    #[ApiProperty(identifier: false)]
    private ?int $id;

    /**
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['category:collection:read', 'category:tree'])]
    protected ?DateTimeInterface $createdAt;

    /**
     * @Gedmo\Timestampable(on="update")
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    protected ?DateTimeInterface $updatedAt;

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'category:collection:read', 'category:tree', 'category:write'])]
    #[ApiProperty(identifier: true)]
    private ?string $name;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $removedAt;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false})
     */
    #[Groups(['category:collection:read', 'category:tree', 'category:write'])]
    private bool $isTechnical;

    /**
     * @ORM\OneToMany(targetEntity="Category", mappedBy="parent", cascade={"remove"})
     */
    #[Groups(['category:tree'])]
    private array|null|ArrayCollection|PersistentCollection $children;

    /**
     * Many Categories have One Parent Category.
     *
     * @ORM\ManyToOne(targetEntity="Category", inversedBy="children")
     * @ORM\JoinColumn(name="parent_id", referencedColumnName="id")
     */
    #[Groups(['category:write'])]
    private ?Category $parent;

    /**
     * Many Categories have One Root Category.
     * @ORM\ManyToOne(targetEntity="Category")
     * @ORM\JoinColumn(name="root_id", referencedColumnName="id")
     */
    private ?Category $root;

    /**
     * @ORM\OneToMany(targetEntity="Transaction", mappedBy="category", orphanRemoval=true, cascade={"remove"})
     */
    private array|null|ArrayCollection|PersistentCollection $transactions;

    /**
     * @ORM\Column(type="boolean", nullable=false)
     */
    #[Groups(['category:collection:read', 'category:tree', 'category:write'])]
    private bool $isAffectingProfit = true;

    /**
     * @ORM\Column(type="string", length=150, nullable=true)
     */
    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read', 'category:collection:read', 'category:tree', 'category:write'])]
    private ?string $frontendIconClass;

    /**
     * @ORM\Column(type="string", length=150, nullable=true)
     */
    #[Groups(['account:item:read', 'debt:collection:read', 'category:collection:read', 'category:tree', 'category:write'])]
    private ?string $frontendColor;

    /**
     * @Assert\Valid()
     *
     * @ORM\ManyToMany(targetEntity=CategoryTag::class, cascade={"persist"}, inversedBy="categories")
     * @ORM\JoinTable(name="categories_tags")
     */
    #[Groups(['account:item:read', 'debt:collection:read', 'category:collection:read', 'category:tree', 'category:write'])]
    private ?Collection $tags;

    #[Groups(['category:collection:read'])]
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

    #[Groups(['transaction:collection:read', 'account:item:read', 'debt:collection:read'])]
    public function getFullPath(): array
    {
        $result = [$this->getName()];

        if(!$this->isRoot() && $this->hasParent()) {
            return array_merge($result, $this->getParent()->getFullPath());
        }

        return $result;
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
        return $this->root ?? $this;
    }

    public function setRoot(?Category $category): self
    {
        $this->root = $category;

        $children = $this->getChildren()->getIterator();
        /** @var Category $child */
        foreach($children as $child) {
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

        if(!$parent) {
            $this->setRoot(null);
        } else {
            $this->setRoot($parent->isRoot() ? $parent : $parent->getRoot());
        }

        return $this;
    }

    #[Groups(['category:tree'])]
    public function getTransactionsCount(bool $withChildren = true): int
    {
        $result = $this->transactions->count();

        if(!$withChildren) {
            return $result;
        }

        foreach($this->children as $child) {
            $result += $child->getTransactionsCount();
        }

        return $result;
    }

    public function getRemovedAt(): ?CarbonInterface
    {
        if($this->removedAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->removedAt->getTimestamp(), $this->removedAt->getTimezone());
        }

        return $this->removedAt;
    }

    public function setRemovedAt(CarbonInterface $removedAt): self
    {
        $this->removedAt = $removedAt;

        return $this;
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

    public function addTransaction(TransactionInterface $transaction): self
    {
        if(!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }

        return $this;
    }

    public function removeTransaction(TransactionInterface $transaction): self
    {
        if($this->transactions->contains($transaction)) {
            $this->transactions->removeElement($transaction);
        }

        return $this;
    }

    public function getValue(): float
    {
        return array_reduce(
            $this->getTransactions(true)->toArray(),
            static function (float $carry, TransactionInterface $transaction) {
                $carry += $transaction->getValue();

                return $carry;
            }, 0);
    }

    public function getTransactions(bool $withDescendants = false): Collection
    {
        if(!$withDescendants) {
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
        if(!$this->children->contains($category)) {
            $this->children->add($category);
        }

        return $this;
    }

    public function removeChildren(Category $category): self
    {
        if($this->children->contains($category)) {
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

    public function getFrontendIconClass(): ?string
    {
        return $this->frontendIconClass;
    }

    public function setFrontendIconClass(?string $frontendIconClass): self
    {
        $this->frontendIconClass = $frontendIconClass;

        return $this;
    }

    public function getFrontendColor(): ?string
    {
        return $this->frontendColor;
    }

    public function setFrontendColor(?string $frontendColor): self
    {
        $this->frontendColor = $frontendColor;

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

    public function directAncestorInRootCategory(Category $rootCategory)
    {
        if($this->getId() === $rootCategory->getId()) {
            return $this;
        }
        if(!$this->isChildOf($rootCategory)) {
            return false;
        }

        $possibleAncestors = $rootCategory->getChildren()->filter(function (Category $category) {
            return $this->isChildOf($category);
        });

        return ($possibleAncestors->count() > 0) ? $possibleAncestors->first() : false;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isChildOf(Category $possibleParent): bool
    {
        return $possibleParent->getDescendantsFlat()->contains($this);
    }

    public function getDescendantsTree(): array
    {
        $result = [];
        foreach($this->children as $child) {
            $result[] = [
                'root' => $child->getRoot()->getId(),
                'parent' => !$child->isRoot() ? $child->getParent()->getId() : null,
                'icon' => $child->getFrontendIconClass(),
                'name' => $child->getName(),
                'children' => !$this->hasChildren() ? [] : $child->getDescendantsTree(),
            ];
        }

        return $result;
    }

    public function getDescendantsFlat(bool $onlyNames = false): Collection
    {
        $result = [$this];

        $this->children->map(static function (Category $child) use (&$result) {
            $result = array_merge($result, $child->getDescendantsFlat()->toArray());
        });

        if($onlyNames) {
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
        foreach($tags as $tag) {
            if(!$this->tags->contains($tag)) {
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
}
