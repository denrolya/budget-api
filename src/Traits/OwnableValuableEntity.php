<?php

namespace App\Traits;

use Symfony\Component\Security\Core\User\UserInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

trait OwnableValuableEntity
{
    /**
     * @Gedmo\Blameable(on="create")
     * @Gedmo\Blameable(on="update")
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="owner_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected ?UserInterface $owner;

    /**
     * @ORM\Column(type="json", nullable=false)
     */
    protected ?array $convertedValues = [];

    public function getOwner(): ?UserInterface
    {
        return $this->owner;
    }

    public function setOwner(UserInterface $user): self
    {
        $this->owner = $user;

        return $this;
    }

    public function getValue(): float
    {
        return $this->convertedValues[$this->getOwner()->getBaseCurrency()];
    }

    public function getConvertedValue(?string $currencyCode): float
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
