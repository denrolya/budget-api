<?php

namespace App\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait ValuableEntity
{
    #[ORM\Column(type: Types::JSON)]
    protected ?array $convertedValues;

    public function getValue(): float|null
    {
        return $this->getConvertedValue($this->getOwner()->getBaseCurrency());
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
