<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\BankCardAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use JMS\Serializer\Annotation as Serializer;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: BankCardAccountRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/accounts/bank', normalizationContext: ['groups' => 'account:write']),
    ],
    denormalizationContext: ['groups' => 'account:write'],
)]
class BankCardAccount extends Account
{
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $bankName;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $cardNumber;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $iban;

    /**
     * The account/balance identifier as the bank knows it.
     * Format is bank-specific: for Wise it's the balanceId, for Monobank it's the account id string.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $externalAccountId = null;

    #[ORM\ManyToOne(targetEntity: BankIntegration::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:collection:read', 'account:item:read'])]
    private ?BankIntegration $bankIntegration = null;

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(string $bankName): self
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getCardNumber(): ?string
    {
        return $this->cardNumber;
    }

    public function setCardNumber(string $cardNumber): self
    {
        $this->cardNumber = $cardNumber;

        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(string $iban): self
    {
        $this->iban = $iban;

        return $this;
    }

    public function getExternalAccountId(): ?string
    {
        return $this->externalAccountId;
    }

    public function setExternalAccountId(?string $externalAccountId): self
    {
        $this->externalAccountId = $externalAccountId;

        return $this;
    }

    public function getBankIntegration(): ?BankIntegration
    {
        return $this->bankIntegration;
    }

    public function setBankIntegration(?BankIntegration $bankIntegration): self
    {
        $this->bankIntegration = $bankIntegration;

        return $this;
    }

    public function getType(): string
    {
        return self::ACCOUNT_TYPE_BANK_CARD;
    }
}
