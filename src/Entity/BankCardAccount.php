<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\BankCardAccountRepository")
 */
class BankCardAccount extends Account
{
    /**
     * @Groups({"account_detail_view"})
     *
     * @ORM\Column(type="string", length=16, nullable=true)
     */
    private ?string $cardNumber;

    /**
     * @Groups({"account_detail_view"})
     *
     * @ORM\Column(type="string", length=34, nullable=true)
     */
    private ?string $iban;

    /**
     * @Groups({"account_detail_view"})
     *
     * @ORM\Column(type="string", length=150, nullable=true)
     */
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
