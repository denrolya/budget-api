<?php

namespace App\Bank\Provider;

use App\Bank\BankProvider;
use App\Bank\BankProviderInterface;
use App\Bank\DTO\BankAccountData;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\WebhookCapableInterface;
use DateTimeImmutable;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Monobank integration.
 *
 * Covers:
 *   - fetchAccounts()       → GET /personal/client-info
 *   - fetchExchangeRates()  → GET /bank/currency (UAH cross rates → base currency relative)
 *   - parseWebhookPayload() → StatementItem webhook events
 *   - getLatest()           → cached wrapper around fetchRates() for ExchangeRatesController
 *
 * externalAccountId on BankCardAccount = Monobank account id string.
 * Monobank amounts are in minor units (kopecks), divide by 100.
 * API key is injected from MONOBANK_API_KEY env (passed as X-Token header).
 */
class MonobankProvider implements BankProviderInterface, WebhookCapableInterface
{
    private const CACHE_TTL = 86400; // 24 hours

    private const CURRENCY_MAP = [
        980 => 'UAH',
        840 => 'USD',
        978 => 'EUR',
        348 => 'HUF',
        826 => 'GBP',
        985 => 'PLN',
        756 => 'CHF',
        203 => 'CZK',
    ];

    public function __construct(
        private readonly HttpClientInterface $monobankClient,  // monobank_client scoped client
        private readonly CacheInterface $cache,
        private readonly string $monobankApiKey,               // MONOBANK_API_KEY env, passed as X-Token header
        private readonly string $baseCurrency,                 // e.g. EUR from parameters.yaml
        private readonly array $allowedCurrencies,             // currencies to include in rate output
    ) {
    }

    public function getProvider(): BankProvider
    {
        return BankProvider::Monobank;
    }

    // -------------------------------------------------------------------------
    // BankProviderInterface
    // -------------------------------------------------------------------------

    /**
     * @return BankAccountData[]
     */
    public function fetchAccounts(array $credentials): array
    {
        try {
            $response = $this->monobankClient->request('GET', '/personal/client-info', [
                'headers' => ['X-Token' => $this->monobankApiKey],
            ]);
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Monobank fetchAccounts failed: ' . $e->getMessage(), 0, $e);
        }

        $result = [];
        foreach ($data['accounts'] ?? [] as $account) {
            $currency = self::CURRENCY_MAP[$account['currencyCode']] ?? (string) $account['currencyCode'];
            $balance = ($account['balance'] ?? 0) / 100;

            $result[] = new BankAccountData(
                externalId: $account['id'],
                name: sprintf('%s %s', $account['maskedPan'][0] ?? 'Monobank', $currency),
                currency: $currency,
                balance: $balance,
            );
        }

        return $result;
    }

    /**
     * Returns UAH-based cross rates converted to base-currency-relative.
     * Result is cached 24 h.
     *
     * @throws InvalidArgumentException
     */
    public function fetchExchangeRates(array $credentials): ?array
    {
        return $this->getLatest();
    }

    // -------------------------------------------------------------------------
    // WebhookCapableInterface
    // -------------------------------------------------------------------------

    /**
     * Parses Monobank StatementItem webhook payload.
     *
     * Expected body:
     * {
     *   "type": "StatementItem",
     *   "data": {
     *     "account": "<accountId>",
     *     "statementItem": {
     *       "time": 1696000000,
     *       "description": "...",
     *       "comment": "...",
     *       "amount": -5000,     // minor units, negative = expense
     *       "currencyCode": 980
     *     }
     *   }
     * }
     */
    public function parseWebhookPayload(array $payload): ?DraftTransactionData
    {
        if (($payload['type'] ?? '') !== 'StatementItem') {
            return null;
        }

        $data = $payload['data'] ?? [];
        $externalAccountId = $data['account'] ?? null;
        $item = $data['statementItem'] ?? [];

        if (!$externalAccountId || empty($item)) {
            return null;
        }

        $amountMinor = (int) ($item['amount'] ?? 0);
        if ($amountMinor === 0) {
            return null;
        }

        $amount = $amountMinor / 100;
        $currency = self::CURRENCY_MAP[$item['currencyCode'] ?? 980] ?? 'UAH';

        $executedAt = isset($item['time'])
            ? (new DateTimeImmutable())->setTimestamp((int) $item['time'])
            : new DateTimeImmutable();

        $description = trim(($item['description'] ?? '') . ' ' . ($item['comment'] ?? ''));

        return new DraftTransactionData(
            externalAccountId: $externalAccountId,
            amount: $amount,
            executedAt: $executedAt,
            note: $description ?: 'Monobank transaction',
            currency: $currency,
        );
    }

    /**
     * Registers the webhook URL with Monobank via POST /personal/webhook.
     * Monobank immediately sends a confirmation ping to the URL before returning.
     */
    public function registerWebhook(array $credentials, string $webhookUrl): void
    {
        try {
            $this->monobankClient->request('POST', '/personal/webhook', [
                'headers' => ['X-Token' => $this->monobankApiKey],
                'json' => ['webHookUrl' => $webhookUrl],
            ])->getContent(); // throws on non-2xx
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Monobank registerWebhook failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // -------------------------------------------------------------------------
    // Exchange rate helpers (used by ExchangeRatesController and fetchExchangeRates)
    // -------------------------------------------------------------------------

    /**
     * Returns currency rates relative to baseCurrency. Cached 24 h.
     *
     * @return array<string, float>
     * @throws InvalidArgumentException
     */
    public function getLatest(): array
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        return $this->cache->get("monobank_rates.{$today}", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetchRates();
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetches /bank/currency endpoint and converts UAH cross-rates to base-currency-relative rates.
     * E.g. if baseCurrency=EUR: rate[USD] = EUR/UAH ÷ USD/UAH.
     *
     * @return array<string, float>
     */
    private function fetchRates(): array
    {
        try {
            $response = $this->monobankClient->request('GET', '/bank/currency');
            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Monobank currency API error: ' . $e->getMessage(), 0, $e);
        }

        $currencyToUahRates = [];

        foreach ($data as $rateInfo) {
            $currencyA = self::CURRENCY_MAP[$rateInfo['currencyCodeA']] ?? null;
            $currencyB = self::CURRENCY_MAP[$rateInfo['currencyCodeB']] ?? null;

            // We want X → UAH rates
            if ($currencyB !== 'UAH' || $currencyA === null) {
                continue;
            }

            if (!in_array($currencyA, $this->allowedCurrencies, true)) {
                continue;
            }

            $rate = $rateInfo['rateCross'] ?? (($rateInfo['rateBuy'] + $rateInfo['rateSell']) / 2);
            $currencyToUahRates[$currencyA] = $rate;
        }

        $currencyToUahRates['UAH'] = 1.0;

        if (!isset($currencyToUahRates[$this->baseCurrency])) {
            throw new RuntimeException(
                sprintf('Monobank: base currency "%s" to UAH rate not available.', $this->baseCurrency)
            );
        }

        $baseToUah = $currencyToUahRates[$this->baseCurrency];

        $rates = [];
        foreach ($currencyToUahRates as $currency => $rateToUah) {
            $rates[$currency] = $baseToUah / $rateToUah;
        }
        $rates[$this->baseCurrency] = 1.0;

        return array_filter($rates, fn($k) => in_array($k, $this->allowedCurrencies, true), ARRAY_FILTER_USE_KEY);
    }
}
