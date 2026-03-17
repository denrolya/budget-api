<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Interface ValuableInterface
 * This interface describes entity which value can be converted using given field
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
