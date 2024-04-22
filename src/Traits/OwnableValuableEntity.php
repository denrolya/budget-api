<?php

namespace App\Traits;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Security\Core\User\UserInterface;

trait OwnableValuableEntity
{
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", nullable: false, onDelete: "CASCADE")]
    protected ?UserInterface $owner = null;

    #[ORM\Column(type: Types::JSON, nullable: false)]
    protected ?array $convertedValues = [];

    public function getValue(): float
    {
        return $this->convertedValues[$this->getOwner()->getBaseCurrency()];
    }

    public function getOwner(): ?UserInterface
    {
        return $this->owner;
    }

    public function setOwner(UserInterface $user): self
    {
        $this->owner = $user;

        return $this;
    }

    public function getConvertedValue(?string $currencyCode = null): float
    {
        return $this->convertedValues[is_null($currencyCode) ? $this->getOwner()->getBaseCurrency() : $currencyCode];
    }

    public function getConvertedValues(): array
    {
        return $this->convertedValues;
    }

    public function setConvertedValues(array $convertedValues): self
    {
        $this->convertedValues = $convertedValues;

        return $this;
    }
}
