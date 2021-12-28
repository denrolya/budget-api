<?php

namespace App\Entity;

use App\Traits\OwnableValuableEntity;
use App\Traits\TimestampableEntity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="AccountRepository::class")
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorColumn(name="type", type="string")
 * @ORM\DiscriminatorMap({"basic" = "Account", "bank" = "BankCardAccount", "internet" = "InternetAccount", "cash" = "CashAccount"})
 */
class Account implements OwnableInterface, ValuableInterface
{
    public const ACCOUNT_TYPE_BASIC = 'basic';
    public const ACCOUNT_TYPE_CASH = 'cash';
    public const ACCOUNT_TYPE_INTERNET = 'internet';
    public const ACCOUNT_TYPE_BANK_CARD = 'bank';

    use TimestampableEntity, OwnableValuableEntity;

    /**
     * #[Groups(["typeahead", "account_list", "account_detail_view", "transaction_list", "account_detail_view", "debt_list", "transfer_list"])]
     *
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id;

    /**
     * @ORM\Column(type="json", nullable=false)
     */
    protected ?array $convertedValues = [];

    /**
     * #[Groups(["account_detail_view"])]
     *
     * @Gedmo\Timestampable(on="create")
     *
     * @ORM\Column(type="datetime", nullable=false)
     */
    protected ?DateTimeInterface $createdAt;

    /**
     * @Gedmo\Timestampable(on="update")
     *
     * @ORM\Column(type="datetime", nullable=false)
     */
    protected ?DateTimeInterface $updatedAt;

    /**
     * #[Groups(["typeahead", "account_list", "transaction_list", "account_detail_view", "debt_list", "transfer_list"])]
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $name;

    /**
     * #[Groups(["account_list", "transaction_list", "account_detail_view", "debt_list", "transfer_list"])]
     *
     * @ORM\ManyToOne(targetEntity="Currency")
     * @ORM\JoinColumn(name="currency_id", referencedColumnName="id", nullable=false)
     */
    private ?Currency $currency;

    /**
     * #[Groups(["account_list", "account_detail_view"])]
     *
     * @ORM\Column(type="decimal", precision=15, scale=5)
     */
    private float $balance = 0;

    /**
     * @var null|array|ArrayCollection|Transaction[]
     *
     * @ORM\OneToMany(targetEntity="Transaction", mappedBy="account", cascade={"remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"executedAt" = "ASC"})
     */
    private $transactions;

    /**
     * #[Groups(["account_list", "account_detail_view"])]
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?DateTimeInterface $archivedAt;

    /**
     * #[Groups(["account_list", "transaction_list", "account_detail_view", "debt_list", "transfer_list"])]
     *
     * @ORM\Column(type="string", length=30)
     */
    private string $color;

    /**
     * @var null|array|ArrayCollection|AccountLogEntry[]
     *
     * @ORM\OneToMany(targetEntity=AccountLogEntry::class, mappedBy="account", orphanRemoval=true)
     * @ORM\OrderBy({"createdAt" = "ASC"})
     */
    private $logs;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();

        $r = random_int(0, 255);
        $g = random_int(0, 255);
        $b = random_int(0, 255);
        $this->color = "rgba($r,$g,$b,1)";
        $this->logs = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getName() ?: 'New Account';
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(Currency $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getBalance(): ?float
    {
        return $this->balance;
    }

    public function setBalance(float $balance): static
    {
        $this->balance = $balance;

        return $this;
    }

    public function updateBalanceBy(float $amount): static
    {
        return $this->setBalance($this->balance + $amount);
    }

    public function increaseBalance(float $amount): static
    {
        return $this->setBalance($this->balance + $amount);
    }

    public function decreaseBalance(float $amount): static
    {
        return $this->setBalance($this->balance - $amount);
    }

    public function addTransaction(Transaction $transaction): static
    {
        if(!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if($this->transactions->contains($transaction)) {
            $this->transactions->removeElement($transaction);
        }

        return $this;
    }

    public function getTransactions()
    {
        return $this->transactions;
    }

    public function getArchivedAt(): ?CarbonInterface
    {
        return $this->archivedAt ? new CarbonImmutable($this->archivedAt->getTimestamp(), $this->archivedAt->getTimezone()) : null;
    }

    public function setArchivedAt(CarbonInterface $archivedAt): static
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }

    public function toggleArchived(): static
    {
        $this->archivedAt = $this->archivedAt === null ? CarbonImmutable::now() : null;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getValuableField(): string
    {
        return 'balance';
    }

    public function getCurrencyCode(): string
    {
        return $this->currency->getCode();
    }

    /**
     * #[Groups(["account_list"])]
     */
    public function getValues(): array
    {
        return $this->convertedValues;
    }

    /**
     * #[Groups(["account_list"])]
     */
    public function getValue(): float
    {
        return $this->convertedValues[$this->getOwner()->getBaseCurrency()];
    }

    /**
     * #[Groups(["account_list", "account_detail_view", "typeahead"])]
     */
    public function getLastTransactionAt()
    {
        if(!$lastTransaction = $this->transactions->last()) {
            return null;
        }

        return $lastTransaction->getCreatedAt();
    }

    /**
     * #[Groups(["account_list", "account_detail_view", "transaction_list", "transfer_list", "deb_list"])]
     */
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

    /**
     * #[Groups(["account_detail_view"])]
     */
    public function getNumberOfTransactions(): int
    {
        return count($this->transactions);
    }

    /**
     * @ORM\PrePersist()
     */
    public function combineNameWithCurrency(): void
    {
        $this->name .= " ({$this->currency->getSymbol()})";
    }

    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function addLog(AccountLogEntry $log): static
    {
        if(!$this->logs->contains($log)) {
            $this->logs[] = $log;
            $log->setAccount($this);
        }

        return $this;
    }

    public function removeLog(AccountLogEntry $log): static
    {
        // set the owning side to null (unless already changed)
        if($this->logs->removeElement($log) && $log->getAccount() === $this) {
            $log->setAccount(null);
        }

        return $this;
    }

    /**
     * #[Groups(["account_detail_view"])]
     */
    public function getLatestTransactions(int $numberOfItems = 10): array
    {
        return array_slice($this->transactions->toArray(), -$numberOfItems, $numberOfItems);
    }

    /**
     * #[Groups(["account_detail_view"])]
     */
    public function getLatestLogs(int $numberOfItems = 80): array
    {
        return array_slice($this->logs->toArray(), -$numberOfItems, $numberOfItems);
    }

    public function getLatestLogEntry(): bool|AccountLogEntry
    {
        return $this->logs->last();
    }

    public function getType(): string
    {
        return self::ACCOUNT_TYPE_BASIC;
    }
}
