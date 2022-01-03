<?php

namespace App\Traits;

use Doctrine\ORM\Mapping as ORM;

trait ValuableEntity
{
    /**
     * @ORM\Column(type="json")
     */
    protected ?array $convertedValues;

    public function getValue(): float|null
    {
        return $this->convertedValue($this->getOwner()->getBaseCurrency());
    }

    public function getConvertedValue(string $currencyCode): float
    {
        return $this->convertedValues[$currencyCode];
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
