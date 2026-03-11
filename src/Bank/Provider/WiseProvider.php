<?php

namespace App\Bank\Provider;

use App\Bank\BankProvider;
use App\Bank\BankProviderInterface;
use App\Bank\DTO\BankAccountData;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\WebhookCapableInterface;
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
 *   - fetchExchangeRates()    → GET /v1/rates (latest, cached)
 *   - getHistorical($date)    → GET /v1/rates?time=... (cached)
 *   - getRates($date)         → convenience: latest or historical
 *   - parseWebhookPayload()   → balances#update / balances#credit events
 *   - registerWebhook()       → POST /v3/profiles/{id}/subscriptions
 *
 * NOTE: Polling (balance statement API) requires SCA which is not automatable
 * for personal API tokens. Use webhooks instead (syncMethod = webhook).
 *
 * externalAccountId on BankCardAccount = Wise balanceId (numeric string).
 * HTTP auth (Bearer token) is configured on the wise_client scoped HTTP client.
 */
class WiseProvider implements BankProviderInterface, WebhookCapableInterface
{
    private const CACHE_TTL = 86400; // 24 hours
    private const WEBHOOK_TRIGGER_UPDATE  = 'balances#update';  // any balance change (credit or debit)
    private const WEBHOOK_TRIGGER_CREDIT  = 'balances#credit';  // money credited to balance
    private const WEBHOOK_DELIVERY_VERSION = '3.0.0';

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

        $balances = $this->requestJson('GET', "/v4/profiles/{$profileId}/balances?types=STANDARD", 'fetchAccounts');

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
    // WebhookCapableInterface
    // -------------------------------------------------------------------------

    public function parseWebhookPayload(array $payload): ?DraftTransactionData
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        if ($eventType !== self::WEBHOOK_TRIGGER_CREDIT && $eventType !== self::WEBHOOK_TRIGGER_UPDATE) {
            return null;
        }

        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            return null;
        }

        // balances#credit v3.0.0 uses a completely different action/resource structure.
        // Detect it by the presence of data.action (not present in v2.0.0 or balances#update).
        if ($eventType === self::WEBHOOK_TRIGGER_CREDIT && isset($data['action']) && is_array($data['action'])) {
            return $this->parseV3CreditPayload($data, $payload);
        }

        // balances#update v3.0.0 and all v2.0.0 events use the same flat data structure.
        return $this->parseFlatPayload($data);
    }

    /**
     * Parses balances#credit v3.0.0 action/resource pattern.
     *
     * Expected shape:
     *   data.action.account_id  → balance account ID (our externalAccountId)
     *   data.resource.settled_amount.value / .currency  → credited amount
     *   data.resource.reference → payment reference (for note)
     *   payload.sent_at → timestamp fallback (no occurred_at in this format)
     */
    private function parseV3CreditPayload(array $data, array $payload): ?DraftTransactionData
    {
        $action = $data['action'];
        $resource = $data['resource'] ?? [];
        if (!is_array($resource)) {
            $resource = [];
        }

        $balanceId = $action['account_id'] ?? null;
        $settledAmount = $resource['settled_amount'] ?? [];
        $amount = isset($settledAmount['value']) ? (float) $settledAmount['value'] : null;
        $currency = isset($settledAmount['currency']) ? (string) $settledAmount['currency'] : null;
        $occurredAt = $payload['sent_at'] ?? null;

        if ($balanceId === null || $amount === null || $currency === null || $occurredAt === null) {
            return null;
        }

        $reference = trim((string) ($resource['reference'] ?? ''));
        $note = $this->usableReference($reference) ? $reference : 'Transfer received';

        return new DraftTransactionData(
            externalAccountId: (string) $balanceId,
            amount: abs($amount), // always positive; it's a credit event
            executedAt: new DateTimeImmutable((string) $occurredAt),
            note: $note,
            currency: $currency,
        );
    }

    /**
     * Parses balances#update (all versions) and balances#credit v2.0.0.
     * All use the same flat data structure with snake_case fields.
     */
    private function parseFlatPayload(array $data): ?DraftTransactionData
    {
        $resource = $data['resource'] ?? [];
        if (!is_array($resource)) {
            $resource = [];
        }

        $balanceId = $data['balance_id'] ?? $resource['id'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $currency = isset($data['currency']) ? (string) $data['currency'] : null;
        $occurredAt = $data['occurred_at'] ?? null;

        if ($balanceId === null || $amount === null || $currency === null || $occurredAt === null) {
            return null;
        }

        $transactionType = strtolower((string) ($data['transaction_type'] ?? 'credit'));
        $signedAmount = $amount;
        if ($transactionType === 'debit') {
            $signedAmount = -abs($amount);
        } elseif ($transactionType === 'credit') {
            $signedAmount = abs($amount);
        }

        $note = $this->buildNoteFromFlatData($data);

        return new DraftTransactionData(
            externalAccountId: (string) $balanceId,
            amount: $signedAmount,
            executedAt: new DateTimeImmutable((string) $occurredAt),
            note: $note,
            currency: $currency,
        );
    }

    public function registerWebhook(array $credentials, string $webhookUrl): void
    {
        $profileId = $this->getPersonalProfileId();

        try {
            $response = $this->wiseClient->request('GET', "/v3/profiles/{$profileId}/subscriptions");
            $subscriptions = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface | TransportExceptionInterface | JsonException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw new RuntimeException($this->formatWiseHttpError('registerWebhook(list)', $e), 0, $e);
            }
            throw new RuntimeException('Wise registerWebhook failed while listing subscriptions: ' . $e->getMessage(), 0, $e);
        }

        // Determine which events still need registration.
        // Also delete stale subscriptions pointing to our URL that use the wrong schema version —
        // Wise schema 2.0.0 omits balance_id in real events; 3.0.0 is required for account matching.
        $eventsToRegister = [self::WEBHOOK_TRIGGER_UPDATE];
        foreach ($subscriptions as $subscription) {
            $event    = $subscription['trigger_on'] ?? null;
            $delivery = $subscription['delivery'] ?? [];
            if (!is_string($event) || !is_array($delivery) || ($delivery['url'] ?? null) !== $webhookUrl) {
                continue;
            }

            $existingVersion = (string) ($delivery['version'] ?? '');
            if ($existingVersion !== self::WEBHOOK_DELIVERY_VERSION) {
                // Wrong schema version — delete so we can recreate with the correct version.
                $subId = $subscription['id'] ?? null;
                if ($subId !== null) {
                    try {
                        $this->wiseClient->request('DELETE', "/v3/profiles/{$profileId}/subscriptions/{$subId}")->getContent();
                    } catch (\Throwable) {
                        // Best-effort; if delete fails we still try to create the new one below.
                    }
                }
                // Leave the event in $eventsToRegister so a fresh 3.0.0 sub gets created.
                continue;
            }

            // Correct version already exists — no need to register this event.
            $eventsToRegister = array_filter($eventsToRegister, fn(string $e) => $e !== $event);
        }

        foreach ($eventsToRegister as $event) {
            try {
                $this->wiseClient->request('POST', "/v3/profiles/{$profileId}/subscriptions", [
                    'json' => [
                        'name'       => sprintf('Budget Wise %s', $event),
                        'trigger_on' => $event,
                        'delivery'   => [
                            'version' => self::WEBHOOK_DELIVERY_VERSION,
                            'url'     => $webhookUrl,
                        ],
                    ],
                ])->getContent();
            } catch (HttpExceptionInterface | TransportExceptionInterface $e) {
                if ($e instanceof HttpExceptionInterface) {
                    $message = $this->formatWiseHttpError('registerWebhook(create)', $e);
                    // 403 = token lacks webhook management permission; treat as a skippable
                    // condition so the command does not fail — register webhooks manually
                    // in Wise UI (Settings → Developer tools → Webhooks) if this occurs.
                    if ($e->getResponse()->getStatusCode() === 403) {
                        throw new \LogicException($message, 0, $e);
                    }
                    throw new RuntimeException($message, 0, $e);
                }
                throw new RuntimeException('Wise registerWebhook failed: ' . $e->getMessage(), 0, $e);
            }
        }
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
            $body = $response->getContent();
            $rates = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Wise rates API error: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new RuntimeException('Wise rates API returned non-JSON response (network block?): ' . $e->getMessage(), 0, $e);
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
            if ($e instanceof HttpExceptionInterface) {
                throw new RuntimeException($this->formatWiseHttpError('fetchProfiles', $e), 0, $e);
            }
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
     * Builds a human-readable note from a flat balances#update / balances#credit-v2 payload.
     *
     * Priority:
     *   CARD channel  → merchant.name > description > "Card payment"
     *   Other channels → description > usable transfer_reference > channel-specific label
     *
     * UUID-like and bare-numeric references are considered internal Wise IDs and are dropped.
     */
    private function buildNoteFromFlatData(array $data): string
    {
        $channel     = strtoupper(trim((string) ($data['channel_name'] ?? '')));
        $description = trim((string) ($data['description'] ?? ''));
        $reference   = trim((string) ($data['transfer_reference'] ?? ''));
        $merchant    = $data['merchant'] ?? [];
        $merchantName = is_array($merchant) ? trim((string) ($merchant['name'] ?? '')) : '';

        // Card transaction: merchant name is the most meaningful piece of info
        if ($channel === 'CARD') {
            if ($merchantName !== '') {
                return $merchantName;
            }
            if ($description !== '') {
                return $description;
            }

            return 'Card payment';
        }

        // Non-card: prefer an explicit description
        if ($description !== '') {
            return $description;
        }

        // Use transfer_reference only when it looks meaningful (not a UUID or bare number)
        if ($this->usableReference($reference)) {
            return $reference;
        }

        // Final fallback: a readable label per channel
        return match ($channel) {
            'BALANCE'    => 'Balance transfer',
            'CONVERSION' => 'Currency conversion',
            'SWIFT'      => 'International transfer',
            'SEPA'       => 'SEPA transfer',
            'DIRECT_DEBIT' => 'Direct debit',
            default      => $channel !== '' ? ucfirst(strtolower($channel)) . ' transaction' : 'Wise transaction',
        };
    }

    /**
     * Returns true when a reference string carries real human meaning.
     * Rejects UUIDs (internal Wise transaction IDs) and bare numeric strings (balance IDs).
     */
    private function usableReference(string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        // UUID — internal Wise transaction identifier, not useful to the user
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference)) {
            return false;
        }

        // Bare integer — usually a balance ID or internal record ID
        if (preg_match('/^\d+$/', $reference)) {
            return false;
        }

        return true;
    }

    /** @throws RuntimeException on API errors */
    private function requestJson(string $method, string $url, string $operation, array $options = []): mixed
    {
        try {
            $response = $this->wiseClient->request($method, $url, $options);

            return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException($this->formatWiseHttpError($operation, $e), 0, $e);
        }
    }

    private function formatWiseHttpError(string $operation, HttpExceptionInterface $e): string
    {
        $status = null;
        $headers = [];

        try {
            $response = $e->getResponse();
            $status = $response->getStatusCode();

            try {
                $headers = $response->getHeaders(false);
            } catch (\Throwable) {
                $headers = [];
            }
        } catch (\Throwable) {
            $status = null;
            $headers = [];
        }

        $approvalResult = strtoupper((string) ($this->firstHeader($headers, 'x-2fa-approval-result') ?? ''));

        if ($status === 403 && $approvalResult === 'REJECTED') {
            return sprintf(
                'Wise %s failed: 403 Forbidden (SCA required). '
                . 'Wise no longer supports API signing for personal accounts — '
                . 'statement polling is unavailable. '
                . 'Switch this integration\'s sync_method to "webhook" and register the webhook via POST /api/bank-integrations/{id}/register-webhook.',
                $operation,
            );
        }

        $body = '';
        try {
            $body = $e->getResponse()->getContent(false);
        } catch (\Throwable) {
        }

        return sprintf('Wise %s failed: %s%s', $operation, $e->getMessage(), $body !== '' ? ' | ' . $body : '');
    }

    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if (is_array($value) && !empty($value)) {
                    return (string) $value[0];
                }

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
