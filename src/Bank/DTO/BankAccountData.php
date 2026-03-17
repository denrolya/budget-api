<?php

declare(strict_types=1);

namespace App\Bank\DTO;

class BankAccountData
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $currency,
        public readonly float $balance,
    ) {
    }
}
