<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\BankCardAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation as Serializer;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: BankCardAccountRepository::class)]
#[ApiResource(
    description: 'A bank card account linked to a BankIntegration for automatic transaction syncing.',
    operations: [
        new Post(
            description: 'Create a new bank card account. Link it to a BankIntegration and set externalAccountId to enable webhook/sync.',
            uriTemplate: '/accounts/bank',
            normalizationContext: ['groups' => 'account:write'],
        ),
    ],
    denormalizationContext: ['groups' => 'account:write'],
)]
class BankCardAccount extends Account
{
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    #[Groups(['account:collection:read', 'account:write'])]
    #[Serializer\Groups(['account:collection:read'])]
    private ?string $bankName;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Groups(['account:collection:read', 'account:write'])]
    #[Serializer\Groups(['account:collection:read'])]
    private ?string $cardNumber;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    #[Groups(['account:collection:read', 'account:write'])]
    #[Serializer\Groups(['account:collection:read'])]
    private ?string $iban;

    /**
     * The account/balance identifier as the bank knows it.
     * Format is bank-specific: for Wise it's the balanceId, for Monobank it's the account id string.
     */
    #[ApiProperty(description: 'The account identifier as known by the bank. Format is provider-specific: Wise balanceId, Monobank account id string.')]
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['account:collection:read', 'account:write'])]
    #[Serializer\Groups(['account:collection:read'])]
    private ?string $externalAccountId = null;

    #[ApiProperty(description: 'The bank integration this account is linked to for automatic transaction syncing via webhooks or polling.')]
    #[ORM\ManyToOne(targetEntity: BankIntegration::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['account:collection:read', 'account:write'])]
    #[Serializer\Groups(['account:collection:read'])]
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
