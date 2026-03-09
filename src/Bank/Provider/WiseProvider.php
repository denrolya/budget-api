<?php

namespace App\Bank\Provider;

use App\Bank\BankProvider;
use App\Bank\BankProviderInterface;
use App\Bank\DTO\BankAccountData;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\PollingCapableInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wise personal account integration.
 *
 * Covers:
 *   - fetchAccounts()         → GET /v4/profiles/{id}/balances
 *   - fetchTransactions()     → GET /v1/profiles/{id}/balances/{bid}/statement.json
 *   - fetchExchangeRates()    → GET /v1/rates (latest, cached)
 *   - getHistorical($date)    → GET /v1/rates?time=... (cached)
 *   - getRates($date)         → convenience: latest or historical
 *
 * externalAccountId on BankCardAccount = Wise balanceId (numeric string).
 * HTTP auth (Bearer token) is configured on the wise_client scoped HTTP client.
 */
class WiseProvider implements BankProviderInterface, PollingCapableInterface
{
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly HttpClientInterface $wiseClient,  // wise_client scoped client (Bearer auth pre-configured)
        private readonly CacheInterface $cache,
        private readonly string $baseCurrency,             // e.g. EUR from parameters.yaml
        private readonly array $allowedCurrencies,         // currencies to include in rate output
    ) {
    }

    public function getProvider(): BankProvider
    {
        return BankProvider::Wise;
    }

    // -------------------------------------------------------------------------
    // BankProviderInterface
    // -------------------------------------------------------------------------

    /**
     * @return BankAccountData[]
     * @throws \Exception
     */
    public function fetchAccounts(array $credentials): array
    {
        $profileId = $this->getPersonalProfileId();

        try {
            $response = $this->wiseClient->request('GET', "/v4/profiles/{$profileId}/balances?types=STANDARD");
            $balances = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Wise fetchAccounts failed: ' . $e->getMessage(), 0, $e);
        }

        $result = [];
        foreach ($balances as $balance) {
            $currency = $balance['totalWorth']['currency'] ?? $balance['amount']['currency'] ?? null;
            $amount = (float) ($balance['totalWorth']['value'] ?? $balance['amount']['value'] ?? 0);

            if ($currency === null) {
                continue;
            }

            $result[] = new BankAccountData(
                externalId: (string) $balance['id'],
                name: sprintf('Wise %s', $currency),
                currency: $currency,
                balance: $amount,
            );
        }

        return $result;
    }

    /**
     * Returns latest rates relative to $baseCurrency. Cached 24 h.
     *
     * @return array<string, float>
     * @throws InvalidArgumentException
     */
    public function fetchExchangeRates(array $credentials): ?array
    {
        return $this->getLatest();
    }

    // -------------------------------------------------------------------------
    // PollingCapableInterface
    // -------------------------------------------------------------------------

    /**
     * @return DraftTransactionData[]
     * @throws \Exception
     */
    public function fetchTransactions(
        array $credentials,
        string $externalAccountId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $profileId = $this->getPersonalProfileId();

        $intervalStart = $from->format('Y-m-d\TH:i:s\Z');
        $intervalEnd = $to->format('Y-m-d\TH:i:s\Z');

        try {
            $url = "/v1/profiles/{$profileId}/balances/{$externalAccountId}/statement.json"
                . "?intervalStart={$intervalStart}&intervalEnd={$intervalEnd}";

            $response = $this->wiseClient->request('GET', $url);
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Wise fetchTransactions failed: ' . $e->getMessage(), 0, $e);
        }

        $result = [];
        foreach ($data['transactions'] ?? [] as $tx) {
            $amount = (float) ($tx['amount']['value'] ?? 0);
            if ($amount === 0.0) {
                continue;
            }

            $result[] = new DraftTransactionData(
                externalAccountId: $externalAccountId,
                amount: $amount,
                executedAt: isset($tx['date']) ? new DateTimeImmutable($tx['date']) : new DateTimeImmutable(),
                note: $this->buildNote($tx),
                currency: $tx['amount']['currency'] ?? null,
            );
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Exchange rate helpers (used by ExchangeRatesController and fetchExchangeRates)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, float>
     * @throws InvalidArgumentException
     */
    public function getLatest(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        return $this->cache->get("wise.latest.{$today}", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetchRates(['source' => $this->baseCurrency]);
        });
    }

    /**
     * @return array<string, float>
     * @throws InvalidArgumentException
     */
    public function getHistorical(CarbonInterface $date): array
    {
        $dateString = $date->toDateString();

        return $this->cache->get("wise.historical.{$dateString}", function (ItemInterface $item) use ($date) {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetchRates([
                'source' => $this->baseCurrency,
                'time' => $date->toIso8601String(),
            ]);
        });
    }

    /**
     * Returns latest if $date is today, historical otherwise.
     *
     * @throws InvalidArgumentException
     */
    public function getRates(?CarbonInterface $date = null): array
    {
        return (!$date || $date->isToday())
            ? $this->getLatest()
            : $this->getHistorical($date);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return array<string, float> */
    private function fetchRates(array $queryParams): array
    {
        try {
            $response = $this->wiseClient->request('GET', '/v1/rates', ['query' => $queryParams]);
            $rates = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Wise rates API error: ' . $e->getMessage(), 0, $e);
        }

        $formatted = [$this->baseCurrency => 1.0];
        foreach ($rates as $rate) {
            if (in_array($rate['target'], $this->allowedCurrencies, true)) {
                $formatted[$rate['target']] = (float) $rate['rate'];
            }
        }

        return $formatted;
    }

    /**
     * Discovers the user's personal profile ID via GET /v2/profiles.
     * Result is NOT cached — call only when needed (fetchAccounts, fetchTransactions).
     */
    private function getPersonalProfileId(): int
    {
        try {
            $response = $this->wiseClient->request('GET', '/v2/profiles');
            $profiles = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface | TransportExceptionInterface | JsonException $e) {
            throw new RuntimeException('Wise: failed to fetch profiles: ' . $e->getMessage(), 0, $e);
        }

        $fallback = null;
        foreach ($profiles as $profile) {
            if (strcasecmp($profile['type'] ?? '', 'personal') === 0) {
                return (int) $profile['id'];
            }
            if ($fallback === null && !empty($profile['id'])) {
                $fallback = (int) $profile['id'];
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new RuntimeException('Wise: no profile found.');
    }

    /**
     * Builds a human-readable note from a Wise transaction object.
     * Prefers description, falls back to merchant name or sender name.
     */
    private function buildNote(array $tx): string
    {
        $details = $tx['details'] ?? [];
        $parts = array_filter([
            $details['description'] ?? null,
            $details['merchant']['name'] ?? null,
            $details['senderName'] ?? null,
        ]);

        return implode(' ', $parts) ?: 'Wise transaction';
    }
}
