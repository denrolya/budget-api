<?php

declare(strict_types=1);

namespace App\Bank;

use RuntimeException;

/**
 * Central registry of all BankProviderInterface implementations.
 * Providers are auto-tagged via Symfony's autoconfigure + #[AutoconfigureTag].
 * We use manual injection here for clarity and to keep things explicit for the MVP.
 */
class BankProviderRegistry
{
    /** @var BankProviderInterface[] keyed by BankProvider->value */
    private array $providers = [];

    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getProvider()->value] = $provider;
        }
    }

    public function get(BankProvider $bankProvider): BankProviderInterface
    {
        $key = $bankProvider->value;

        if (!isset($this->providers[$key])) {
            throw new RuntimeException(\sprintf('No provider registered for bank "%s".', $key));
        }

        return $this->providers[$key];
    }

    public function all(): array
    {
        return array_values($this->providers);
    }
}
