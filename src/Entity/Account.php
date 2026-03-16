<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Serializer\Filter\PropertyFilter;
use App\ApiPlatform\AccountCollectionProvider;
use App\Repository\AccountRepository;
use App\Traits\OwnableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Context;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\InheritanceType("SINGLE_TABLE")]
#[ORM\DiscriminatorColumn(name: "type", type: "string")]
#[ORM\DiscriminatorMap([
  "basic" => self::class,
  "bank" => BankCardAccount::class,
  "internet" => InternetAccount::class,
  "cash" => CashAccount::class,
])]
#[ApiResource(
  description: 'A financial account (bank card, cash, internet wallet, or basic). Uses single-table inheritance with a `type` discriminator.',
  operations: [
    new GetCollection(
      description: 'Retrieve all accounts for the authenticated user, ordered by last update. Includes draftCount and converted balances.',
      normalizationContext: ['groups' => ['account:collection:read']],
      provider: AccountCollectionProvider::class,
    ),
    new Post(
      description: 'Create a new basic account. Use POST /accounts/bank for bank card accounts.',
      normalizationContext: ['groups' => 'account:write'],
    ),
    new Put(
      description: 'Update an existing account (name, balance, currency, archive status, sidebar visibility).',
      requirements: ['id' => '\d+'],
      normalizationContext: ['groups' => ['account:write', 'bank_integration:read']],
    ),
  ],
  denormalizationContext: ['groups' => 'account:write'],
  order: ['updatedAt' => 'DESC'],
  paginationEnabled: false
)]
#[ApiFilter(PropertyFilter::class)]
#[Serializer\Discriminator([
  'field' => 'type',
  'groups' => ['account:collection:read', 'account:write', 'debt:collection:read'],
  'map' => [
    'basic' => Account::class,
    'bank' => BankCardAccount::class,
    'internet' => InternetAccount::class,
    'cash' => CashAccount::class,
  ],
  'disabled' => false,
])]
class Account implements OwnableInterface
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

  use TimestampableEntity, OwnableEntity;

  #[ORM\Id]
  #[ORM\GeneratedValue]
  #[ORM\Column(type: Types::INTEGER)]
  #[Groups([
    'account:collection:read',
    'account:write',
    'debt:collection:read',
    'transfer:collection:read',
    'transaction:collection:read',
  ])]
  #[Serializer\Groups([
    'account:collection:read',
    'account:write',
    'transaction:collection:read',
    'debt:collection:read',
    'transfer:collection:read',
  ])]
  private ?int $id;

  #[Gedmo\Timestampable(on: 'create')]
  #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
  #[Groups(['account:collection:read'])]
  #[Serializer\Groups(['account:collection:read', 'account:write'])]
  protected ?DateTimeInterface $createdAt;

  #[Gedmo\Timestampable(on: 'update')]
  #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: false)]
  #[Groups(['account:collection:read', 'account:write'])]
  #[Serializer\Groups(['account:collection:read', 'account:write'])]
  protected ?DateTimeInterface $updatedAt;

  #[Assert\NotBlank]
  #[ORM\Column(type: Types::STRING, length: 255)]
  #[Groups([
    'account:collection:read',
    'account:write',
    'transaction:collection:read',
    'debt:collection:read',
    'transfer:collection:read',
  ])]
  #[Serializer\Groups([
    'account:collection:read',
    'account:write',
    'transaction:collection:read',
    'debt:collection:read',
    'transfer:collection:read',
  ])]
  private string $name;

  #[Assert\NotBlank]
  #[Assert\Choice(["EUR", "USD", "UAH", "HUF", "BTC", "ETH"])]
  #[ORM\Column(type: Types::STRING, length: 3)]
  #[Groups([
    'account:collection:read',
    'account:write',
    'transaction:collection:read',
    'debt:collection:read',
    'transfer:collection:read',
  ])]
  #[ApiProperty(
    schema: [
      'type' => 'string',
      'enum' => ['EUR', 'UAH', 'USD', 'HUF', 'BTC', 'ETH'],
      'example' => 'EUR',
    ],
  )]
  #[Serializer\Groups([
    'account:collection:read',
    'account:write',
    'transaction:collection:read',
    'debt:collection:read',
    'transfer:collection:read',
  ])]
  private ?string $currency;

  #[Assert\NotBlank]
  #[Assert\Type('numeric')]
  #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 8)]
  #[Groups(['account:collection:read', 'account:write'])]
  #[Serializer\Groups(['account:collection:read', 'account:write'])]
  #[Context(denormalizationContext: [AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true])]
  #[Serializer\Type(Types::FLOAT)]
  private string $balance = '0.0';

  #[ORM\OneToMany(mappedBy: "account", targetEntity: Transaction::class, cascade: ["remove"], fetch: "EXTRA_LAZY", orphanRemoval: true)]
  #[ORM\OrderBy(["executedAt" => "ASC"])]
  private Collection $transactions;

  #[ApiProperty(description: 'When set, the account is considered archived and hidden from active views.')]
  #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
  #[Groups(['account:collection:read', 'account:write'])]
  #[Serializer\Groups(['account:collection:read', 'account:write'])]
  private ?DateTimeInterface $archivedAt;

  #[ApiProperty(description: 'Whether this account appears in the sidebar navigation for quick access.')]
  #[ORM\Column(type: Types::BOOLEAN)]
  #[Groups(['account:collection:read', 'account:write'])]
  #[Serializer\Groups(['account:collection:read', 'account:write'])]
  private bool $isDisplayedOnSidebar = false;

  #[ApiProperty(description: 'Number of unconfirmed (draft) transactions on this account. Computed at read time, not persisted.')]
  #[Groups(['account:collection:read'])]
  private int $draftCount = 0;

  public function __construct()
  {
    $this->transactions = new ArrayCollection();
  }

  public function __toString(): string
  {
    return $this->getName() ?? 'New Account';
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
    return (float) $this->balance;
  }

  public function setBalance(string|float|int $balance): self
  {
    $this->balance = (string) $balance;

    return $this;
  }

  public function updateBalanceBy(float $amount): self
  {
    return $this->setBalance((float) $this->balance + $amount);
  }

  public function increaseBalance(float $amount): self
  {
    return $this->setBalance((float) $this->balance + $amount);
  }

  public function decreaseBalance(float $amount): self
  {
    return $this->setBalance((float) $this->balance - $amount);
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

  public function getTransactions(): Collection
  {
    return $this->transactions;
  }

  public function getTransactionsCount(): int
  {
    return $this->transactions->count();
  }

  public function getArchivedAt(): ?CarbonInterface
  {
    return $this->archivedAt ? new CarbonImmutable(
      $this->archivedAt->getTimestamp(),
      $this->archivedAt->getTimezone()
    ) : null;
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

  public function getValuableField(): string
  {
    return 'balance';
  }

  public function getIsDisplayedOnSidebar(): bool
  {
    return $this->isDisplayedOnSidebar;
  }

  public function setIsDisplayedOnSidebar(bool $isDisplayedOnSidebar): self
  {
    $this->isDisplayedOnSidebar = $isDisplayedOnSidebar;

    return $this;
  }

  public function getDraftCount(): int
  {
    return $this->draftCount;
  }

  public function setDraftCount(int $draftCount): self
  {
    $this->draftCount = $draftCount;

    return $this;
  }

  #[Groups(['account:collection:read', 'account:write'])]
  public function getType(): string
  {
    return self::ACCOUNT_TYPE_BASIC;
  }
}
