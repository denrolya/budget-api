<?php

namespace App\Entity;

use App\Repository\AccountLogEntryRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity(repositoryClass=AccountLogEntryRepository::class)
 */
class AccountLogEntry
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    #[Groups(['account:item:read'])]
    private ?int $id;

    /**
     * @ORM\ManyToOne(targetEntity=Account::class, inversedBy="logs")
     * @ORM\JoinColumn(nullable=false)
     */
    private ?Account $account;

    /**
     * @ORM\Column(type="decimal", precision=50, scale=30, nullable=true)
     */
    private float $balance;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    #[Groups(['account:item:read'])]
    private array $convertedValues;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?DateTimeInterface $createdAt;

    public function __construct(Account $account, float $balance, array $convertedValues, CarbonInterface $createdAt)
    {
        $this->account = $account;
        $this->balance = $balance;
        $this->convertedValues = $convertedValues;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): self
    {
        $this->account = $account;

        return $this;
    }

    public function getBalance(): ?string
    {
        return $this->balance;
    }

    public function setBalance(?string $balance): self
    {
        $this->balance = $balance;

        return $this;
    }

    public function getConvertedValues(): ?array
    {
        return $this->convertedValues;
    }

    public function getConvertedValue(string $code): float
    {
        return $this->convertedValues[$code];
    }

    public function setConvertedValues(?array $convertedValues): self
    {
        $this->convertedValues = $convertedValues;

        return $this;
    }

    public function getCreatedAt(): ?CarbonInterface
    {
        if($this->createdAt instanceof DateTimeInterface) {
            return new CarbonImmutable($this->createdAt->getTimestamp(), $this->createdAt->getTimezone());
        }

        return $this->createdAt;
    }

    #[Groups(['account:item:read'])]
    public function getDate(): float|int|string
    {
        return $this->getCreatedAt()->timestamp;
    }

    public function setCreatedAt(CarbonInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
