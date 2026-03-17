<?php

declare(strict_types=1);

namespace App\Bank\DTO;

use DateTimeInterface;

class DraftTransactionData
{
    public function __construct(
        /** External account ID (as the bank identifies it) */
        public readonly string $externalAccountId,
        /** Positive = income, negative = expense (in account currency, not minor units) */
        public readonly float $amount,
        public readonly DateTimeInterface $executedAt,
        public readonly string $note,
        /** Currency code as reported by the bank (may differ from account currency for FX transactions) */
        public readonly ?string $currency = null,
    ) {
    }
}
