<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use ApiPlatform\Core\Action\NotFoundAction;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\BankCardAccountRepository")
 */
#[ApiResource(
    collectionOperations: [
        'create' => [
            'method' => 'POST',
            'path' => '/accounts/bank',
            "denormalization_context" => [
                "groups" => "account:create",
            ],
            "normalization_context" => [
                "groups" => "account:create",
            ],
        ],
    ],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
)]
class BankCardAccount extends Account
{
    /**
     * @ORM\Column(type="string", length=16, nullable=true)
     */
    #[Groups(['account:details', "account:create"])]
    private ?string $cardNumber;

    /**
     * @ORM\Column(type="string", length=34, nullable=true)
     */
    #[Groups(['account:details', "account:create"])]
    private ?string $iban;

    /**
     * @ORM\Column(type="string", length=150, nullable=true)
     */
    #[Groups(['account:details', "account:create"])]
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
