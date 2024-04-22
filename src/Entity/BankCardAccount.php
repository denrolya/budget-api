<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\BankCardAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Core\Action\NotFoundAction;
use JMS\Serializer\Annotation as Serializer;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: BankCardAccountRepository::class)]
#[ApiResource(
    collectionOperations: [
        'post' => [
            'path' => '/accounts/bank',
            'normalization_context' => ['groups' => 'account:write'],
        ],
    ],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
    denormalizationContext: ['groups' => 'account:write'],
)]
class BankCardAccount extends Account
{
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $cardNumber;

    #[ORM\Column(type: Types::STRING, length: 34, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $iban;

    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    #[Groups(['account:item:read', 'account:write'])]
    #[Serializer\Groups(['account:item:read'])]
    private ?string $monobankId;

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

    public function getMonobankId(): ?string
    {
        return $this->monobankId;
    }

    public function setMonobankId(string $monobankId): self
    {
        $this->monobankId = $monobankId;

        return $this;
    }

    public function getType(): string
    {
        return self::ACCOUNT_TYPE_BANK_CARD;
    }
}
