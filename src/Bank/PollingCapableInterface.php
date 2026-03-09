<?php

namespace App\Bank;

use App\Bank\DTO\DraftTransactionData;
use DateTimeImmutable;

/**
 * Implement this on providers that support periodic polling / CSV import.
 * Examples: Wise (REST API statement), Raiffeisen (CSV)
 */
interface PollingCapableInterface
{
    /**
     * Fetch transactions for a given account and date range.
     *
     * @param array           $credentials       Bank credentials (apiKey, profileId, etc.)
     * @param string          $externalAccountId The bank-side account/balance identifier
     * @param DateTimeImmutable $from
     * @param DateTimeImmutable $to
     * @return DraftTransactionData[]
     */
    public function fetchTransactions(
        array $credentials,
        string $externalAccountId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
