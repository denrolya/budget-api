<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Serializer\Filter\PropertyFilter;
use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\Collection;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\AccountRepository;
use Symfony\Component\Serializer\Annotation\SerializedName;
use ApiPlatform\Core\Annotation\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass=AccountRepository::class)
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"basic" = "Account", "bank" = "BankCardAccount", "internet" = "InternetAccount", "cash" = "CashAccount"})
 */
#[ApiResource(
    collectionOperations: [
        'get' => [
            'normalization_context' => ['groups' => 'account:collection:read'],
        ],
        'post' => [
            'normalization_context' => ['groups' => 'account:write'],
        ],
    ],
    itemOperations: [
        'get' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'account:item:read'],
        ],
        'put' => [
            'requirements' => ['id' => '\d+'],
            'normalization_context' => ['groups' => 'account:write'],
        ],
    ],
    denormalizationContext: ['groups' => 'account:write'],
    order: ['updatedAt' => 'DESC'],
    paginationEnabled: false
)]
#[ApiFilter(PropertyFilter::class)]
class Account implements OwnableInterface, ValuableInterface
{
    public const CURRENCIES = [
        'EUR' => [
            'name' => 'Euro',
            'code' => 'EUR',
            'symbol' => '€',
        ],
        'USD' => [
            'name' => 'US Dollar',
            'code' => 'USD',
            'symbol' => '$',
        ],
        'UAH' => [
            'name' => 'Ukrainian Hryvnia',
            'code' => 'UAH',
            'symbol' => '₴',
        ],
        'HUF' => [
            'name' => 'Hungarian Forint',
            'code' => 'HUF',
            'symbol' => 'Ft.',
        ],
    ];

    public const ACCOUNT_TYPE_BASIC = 'basic';
    public const ACCOUNT_TYPE_CASH = 'cash';
    public const ACCOUNT_TYPE_INTERNET = 'internet';
    public const ACCOUNT_TYPE_BANK_CARD = 'bank';

    use TimestampableEntity, OwnableValuableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    #[Groups(['account:collection:read', 'account:item:read', 'account:item:read', 'debt:collection:read', 'transfer:collection:read', 'transaction:collection:read'])]
    private ?int $id;

    /**
     * @ORM\Column(type="json", nullable=false)
     */
    #[Groups(['account:collection:read'])]
    protected ?array $convertedValues = [];

    /**
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=false)
     */
    #[Groups(['account:item:read'])]
    protected ?DateTimeInterface $createdAt;

    /**
     * @Gedmo\Timestampable(on="update")
     *
     * @ORM\Column(type="datetime", nullable=false)
     */
    #[Groups(['account:collection:read'])]
    protected ?DateTimeInterface $updatedAt;

    /**
     * @Assert\NotBlank()
     * @ORM\Column(type="string", length=255)
     */
    #[Groups(['account:collection:read', 'account:item:read', 'account:write', 'transaction:collection:read', 'debt:collection:read', 'transfer:collection:read'])]
    private string $name;

    /**
     * Currency that account is operating with
     *
     * @var ?string
     *
     * @Assert\NotBlank()
     * @Assert\Choice({"EUR", "USD", "UAH", "HUF", "BTC", "ETH"})
     * @ORM\Column(type="string", length=3)
     */
    #[Groups(['account:collection:read', 'transaction:collection:read', 'account:item:read', 'account:write', 'debt:collection:read', 'transfer:collection:read'])]
    #[ApiProperty(
        attributes: [
            'openapi_context' => [
                'type' => 'string',
                'enum' => ['EUR', 'UAH', 'USD', 'HUF', 'BTC', 'ETH'],
                'example' => 'EUR',
            ],
        ],
    )]
    private ?string $currency;

    /**
     * Initial balance of the account
     *
     * @ORM\Column(type="decimal", precision=15, scale=10)
     */
    #[Groups(['account:collection:read', 'account:item:read', 'account:write'])]
    private float $balance = 0;

    /**
     * @ORM\OneToMany(targetEntity="Transaction", mappedBy="account", cascade={"remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"executedAt" = "ASC"})
     */
    private ?Collection $transactions;

    /**
     * When the account was archived(excluded from lists)
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    #[Groups(['account:collection:read', 'account:item:read', 'account:write'])]
    private ?DateTimeInterface $archivedAt;

    /**
     * @ORM\Column(type="string", length=30)
     */
    #[Groups(['account:collection:read', 'transaction:collection:read', 'account:item:read', 'account:write', 'debt:collection:read', 'transfer:collection:read'])]
    private string $color;

    /**
     * @ORM\OneToMany(targetEntity=AccountLogEntry::class, mappedBy="account", orphanRemoval=true)
     * @ORM\OrderBy({"createdAt" = "ASC"})
     */
    private ?Collection $logs;

    #[Groups(['account:item:read'])]
    #[ApiProperty(
        attributes: [
            "openapi_context" => [
                "type" => "array",
                "example" => "[]",
            ],
        ],
    )]
    private ?array $topExpenseCategories;

    #[Groups(['account:item:read'])]
    #[ApiProperty(
        attributes: [
            "openapi_context" => [
                "type" => "array",
                "example" => "[]",
            ],
        ],
    )]
    private ?array $topIncomeCategories;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->color = '#' . str_pad(dechex(random_int(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
        $this->logs = new ArrayCollection();
    }

    #[Pure] public function __toString(): string
    {
        return $this->getName() ?: 'New Account';
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getBalance(): ?float
    {
        return $this->balance;
    }

    public function setBalance(float $balance): self
    {
        $this->balance = $balance;

        return $this;
    }

    public function updateBalanceBy(float $amount): self
    {
        return $this->setBalance($this->balance + $amount);
    }

    public function increaseBalance(float $amount): self
    {
        return $this->setBalance($this->balance + $amount);
    }

    public function decreaseBalance(float $amount): self
    {
        return $this->setBalance($this->balance - $amount);
    }

    public function addTransaction(Transaction $transaction): self
    {
        if(!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): self
    {
        if($this->transactions->contains($transaction)) {
            $this->transactions->removeElement($transaction);
        }

        return $this;
    }

    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function getArchivedAt(): ?CarbonInterface
    {
        return $this->archivedAt ? new CarbonImmutable($this->archivedAt->getTimestamp(), $this->archivedAt->getTimezone()) : null;
    }

    public function setArchivedAt(?DateTimeInterface $archivedAt): self
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function toggleArchived(): self
    {
        $this->archivedAt = $this->archivedAt === null ? CarbonImmutable::now() : null;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getValuableField(): string
    {
        return 'balance';
    }

    #[Groups(['account:collection:read', 'account:item:read'])]
    public function getLastTransactionAt()
    {
        if(!$lastTransaction = $this->transactions->last()) {
            return null;
        }

        return $lastTransaction->getCreatedAt();
    }

    #[Groups(['account:collection:read', 'account:item:read', 'transaction:collection:read', 'transfer:collection:read', 'debt:collection:read'])]
    #[Pure]
    public function getIcon(): string
    {
        $icons = [
            self::ACCOUNT_TYPE_CASH => 'ion-ios-cash',
            self::ACCOUNT_TYPE_INTERNET => 'ion-ios-globe',
            self::ACCOUNT_TYPE_BANK_CARD => 'ion-ios-card',
            self::ACCOUNT_TYPE_BASIC => 'ion-ios-wallet',
        ];

        return $icons[$this->getType()];
    }

    #[Groups(['account:item:read'])]
    public function getNumberOfTransactions(): int
    {
        return count($this->transactions);
    }

    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function addLog(AccountLogEntry $log): self
    {
        if(!$this->logs->contains($log)) {
            $this->logs[] = $log;
            $log->setAccount($this);
        }

        return $this;
    }

    public function removeLog(AccountLogEntry $log): self
    {
        // set the owning side to null (unless already changed)
        if($this->logs->removeElement($log) && $log->getAccount() === $this) {
            $log->setAccount(null);
        }

        return $this;
    }

    #[Groups(['account:item:read'])]
    public function getLatestTransactions(int $numberOfItems = 10): array
    {
        return array_slice($this->transactions->toArray(), -$numberOfItems, $numberOfItems);
    }

    #[Groups(['account:item:read'])]
    #[SerializedName("logs")]
    public function getLogsWithinDateRange(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if(!$from && !$to) {
            $to = CarbonImmutable::now();
            $from = $to->sub('months', 6)->startOf('day');
        }

        return array_values($this->logs->filter(static function (AccountLogEntry $log) use ($from, $to) {
            return $log->getCreatedAt()->isBetween($from, $to);
        })->toArray());
    }

    public function getLatestLogEntry(): bool|AccountLogEntry
    {
        return $this->logs->last();
    }

    public function getTopExpenseCategories(): array
    {
        if($this->topExpenseCategories === null) {
            throw new \LogicException('The isMe field has not been initialized');
        }

        return $this->topExpenseCategories;
    }

    public function setTopExpenseCategories(array $topExpenseCategories): self
    {
        $this->topExpenseCategories = $topExpenseCategories;

        return $this;
    }

    public function getTopIncomeCategories(): array
    {
        if($this->topIncomeCategories === null) {
            throw new \LogicException('The isMe field has not been initialized');
        }

        return $this->topIncomeCategories;
    }

    public function setTopIncomeCategories(array $topIncomeCategories): self
    {
        $this->topIncomeCategories = $topIncomeCategories;

        return $this;
    }

    #[Groups(['account:item:read'])]
    public function getType(): string
    {
        return self::ACCOUNT_TYPE_BASIC;
    }
}
