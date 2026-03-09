<?php

namespace App\Bank;

use App\Bank\DTO\BankAccountData;

/**
 * Every bank provider must implement this interface.
 * One bank = one class. All bank-specific logic lives here.
 */
interface BankProviderInterface
{
    public function getProvider(): BankProvider;

    /**
     * Fetch the list of accounts/balances from the bank.
     * Credentials are passed explicitly so providers are stateless and work
     * both with env-based credentials (MVP) and future per-user stored credentials.
     *
     * @param array $credentials  Key-value pairs specific to this bank (e.g. ['apiKey' => '...'])
     * @return BankAccountData[]
     */
    public function fetchAccounts(array $credentials): array;

    /**
     * Fetch the latest exchange rates from this bank, if available.
     * Returns null if the bank does not provide exchange rates.
     *
     * @param array $credentials
     * @return array<string, float>|null  Map of currency-code => rate-relative-to-base
     */
    public function fetchExchangeRates(array $credentials): ?array;
}
