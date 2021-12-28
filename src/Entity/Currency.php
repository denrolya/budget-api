<?php

namespace App\Entity;

use App\Traits\TimestampableEntity;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\CurrencyRepository")
 */
class Currency
{
    use TimestampableEntity;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private ?int $id;

    /**
     * #[Groups(["account_list", "typeahead", "debt_list"])]
     *
     * @ORM\Column(type="string", length=100)
     */
    private ?string $name;

    /**
     * #[Groups(["account_list", "account_detail_view", "typeahead", "debt_list"])]
     *
     * @ORM\Column(type="string", length=5)
     */
    private ?string $code;

    /**
     * #[Groups(["account_list", "transaction_list", "account_detail_view", "debt_list", "transfer_list", "debt_list"])]
     *
     * @ORM\Column(type="string", length=5)
     */
    private ?string $symbol;

    public function __toString()
    {
        return $this->symbol ?: '';
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }
}
