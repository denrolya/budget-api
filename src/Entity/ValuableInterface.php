<?php

namespace App\Entity;

/**
 * Interface ValuableInterface
 * This interface describes entity which value can be converted using given field
 *
 * @package App\Entity
 */
interface ValuableInterface
{
    public function getValue(): float;

    public function getConvertedValue(string $currencyCode): float;

    public function getConvertedValues(): array;

    public function setConvertedValues(array $convertedValues): self;

    public function getValuableField(): string;

    public function getCurrency(): string;
}
