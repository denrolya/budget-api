<?php

namespace App\Bank\Provider;

use App\Bank\BankProvider;
use App\Bank\BankProviderInterface;
use App\Bank\DTO\BankAccountData;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\PollingCapableInterface;
use App\Bank\WebhookCapableInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
class WiseProvider implements BankProviderInterface, WebhookCapableInterface, PollingCapableInterface
{
    private const CACHE_TTL = 86400; // 24 hours
    private const WEBHOOK_TRIGGER_UPDATE    = 'balances#update';              // any balance change (credit or debit)
    private const WEBHOOK_TRIGGER_CREDIT    = 'balances#credit';              // money credited to balance
    private const WEBHOOK_TRIGGER_CARD_TX   = 'cards#transaction-state-change'; // card tx with merchant data
    private const WEBHOOK_DELIVERY_VERSION      = '3.0.0';
    private const WEBHOOK_CARD_DELIVERY_VERSION = '2.1.0'; // v2.1.0 adds debits[].balance_id + creation_time

    public function __construct(
        private readonly HttpClientInterface $wiseClient,  // wise_client scoped client (Bearer auth pre-configured)
        private readonly CacheInterface $cache,
        private readonly string $baseCurrency,             // e.g. EUR from parameters.yaml
        private readonly array $allowedCurrencies,         // currencies to include in rate output
        #[Autowire(service: 'monolog.logger.bank')]
        private readonly LoggerInterface $logger = new \Psr\Log\NullLogger(),
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
    // PollingCapableInterface — Activities API (no SCA required)
    // -------------------------------------------------------------------------

    /**
     * Fetches transactions for a Wise balance via the Activities API.
     *
     * The Activities API (GET /v1/profiles/{id}/activities) does not return balance_id
     * per activity, so we match by currency: we look up the balance's currency from
     * GET /v4/profiles/{id}/balances (cached) and filter activities by that currency.
     *
     * @return DraftTransactionData[]
     */
    public function fetchTransactions(
        array $credentials,
        string $externalAccountId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $profileId      = $this->getPersonalProfileId();
        $balanceCurrency = $this->getBalanceCurrency($profileId, $externalAccountId);

        if ($balanceCurrency === null) {
            $this->logger->warning('[Wise] fetchTransactions: cannot resolve currency for balance {bid}', [
                'bid' => $externalAccountId,
            ]);

            return [];
        }

        $results = [];
        $cursor  = null;

        do {
            $query = [
                'since'  => $from->format('c'),
                'until'  => $to->format('c'),
                'status' => 'COMPLETED',
                'size'   => 100,
            ];
            if ($cursor !== null) {
                $query['nextCursor'] = $cursor;
            }

            try {
                $page = $this->requestJson('GET', "/v1/profiles/{$profileId}/activities", 'fetchTransactions', [
                    'query' => $query,
                ]);
            } catch (RuntimeException $e) {
                throw new RuntimeException("Wise fetchTransactions failed: {$e->getMessage()}", 0, $e);
            }

            $activities = $page['activities'] ?? [];
            $cursor     = $page['cursor'] ?? null;

            foreach ($activities as $activity) {
                $primary  = $activity['primaryAmount'] ?? [];
                $currency = isset($primary['currency']) ? (string) $primary['currency'] : null;

                // Filter: only activities matching the requested balance's currency
                if ($currency !== $balanceCurrency) {
                    continue;
                }

                $value = isset($primary['value']) ? (float) $primary['value'] : null;
                if ($value === null) {
                    continue;
                }

                $title       = strip_tags(trim((string) ($activity['title'] ?? '')));
                $description = trim((string) ($activity['description'] ?? ''));
                $createdOn   = $activity['createdOn'] ?? null;

                if ($createdOn === null) {
                    continue;
                }

                // Note: prefer title (often merchant name), fall back to description, then empty.
                $note = $title !== '' ? $title : ($description !== '' ? $description : '');

                $results[] = new DraftTransactionData(
                    externalAccountId: $externalAccountId,
                    amount: $value, // Activities API returns signed values (negative for debits)
                    executedAt: new DateTimeImmutable((string) $createdOn),
                    note: $note,
                    currency: $currency,
                );
            }
        } while ($cursor !== null && !empty($activities));

        $this->logger->info('[Wise] fetchTransactions: {n} activities for balance {bid} ({cur})', [
            'n'   => count($results),
            'bid' => $externalAccountId,
            'cur' => $balanceCurrency,
        ]);

        return $results;
    }

    /**
     * Resolves the currency for a given balance ID via the balances API (cached per request).
     */
    private function getBalanceCurrency(int $profileId, string $balanceId): ?string
    {
        try {
            $balances = $this->cache->get("wise.balances.{$profileId}", function (ItemInterface $item) use ($profileId) {
                $item->expiresAfter(300); // 5 min cache for balance list

                return $this->requestJson('GET', "/v4/profiles/{$profileId}/balances?types=STANDARD", 'getBalanceCurrency');
            });
        } catch (RuntimeException) {
            return null;
        }

        foreach ($balances as $balance) {
            if ((string) ($balance['id'] ?? '') === $balanceId) {
                return $balance['totalWorth']['currency'] ?? $balance['amount']['currency'] ?? null;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // WebhookCapableInterface
    // -------------------------------------------------------------------------

    public function parseWebhookPayload(array $payload): ?DraftTransactionData
    {
        $eventType     = (string) ($payload['event_type'] ?? '');
        $schemaVersion = (string) ($payload['schema_version'] ?? 'unknown');

        $this->logger->info('[Wise] Webhook received: event={event} schema={schema}', [
            'event'  => $eventType,
            'schema' => $schemaVersion,
        ]);

        // cards#transaction-state-change (v2.1.0) carries merchant name and balance_id.
        // This is the primary source for card purchase transactions.
        if ($eventType === self::WEBHOOK_TRIGGER_CARD_TX) {
            return $this->parseCardTransactionPayload($payload['data'] ?? [], $payload);
        }

        if ($eventType !== self::WEBHOOK_TRIGGER_CREDIT && $eventType !== self::WEBHOOK_TRIGGER_UPDATE) {
            $this->logger->info('[Wise] Unrecognised event type, skipping.', ['event' => $eventType]);

            return null;
        }

        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            $this->logger->warning('[Wise] Payload data is not an array.', ['event' => $eventType]);

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
     * Parses cards#transaction-state-change (v2.1.0).
     *
     * Only COMPLETED transactions are processed.
     * v2.1.0 adds debits[].balance_id, debits[].creation_time, debits[].rate.
     *
     * Note format (only real data, empty if nothing meaningful):
     *   Same currency:       "Lidl Budapest"
     *   Cross-currency:      "Lidl Budapest · 15.20 EUR → 5692 HUF @ 374.47"
     *   No merchant:         "Budapest, HU" or "15.20 EUR → 5692 HUF @ 374.47" or ""
     */
    private function parseCardTransactionPayload(mixed $data, array $payload): ?DraftTransactionData
    {
        if (!is_array($data)) {
            $this->logger->warning('[Wise] cards#tx: data is not an array.');

            return null;
        }

        $state  = strtoupper((string) ($data['transaction_state'] ?? ''));
        $txType = strtolower((string) ($data['transaction_type'] ?? ''));

        $this->logger->info('[Wise] cards#tx: state={state} type={type}', [
            'state' => $state,
            'type'  => $txType,
        ]);

        if ($state !== 'COMPLETED') {
            return null;
        }

        $debits  = is_array($data['debits'] ?? null)  ? $data['debits']  : [];
        $credits = is_array($data['credits'] ?? null) ? $data['credits'] : [];
        $isDebit = !empty($debits);
        $entries = $isDebit ? $debits : $credits;

        if (empty($entries)) {
            $this->logger->warning('[Wise] cards#tx: no debits/credits — v2.0.0 payload, cannot match account.');

            return null;
        }

        $firstEntry  = $entries[0];
        $balanceId   = $firstEntry['balance_id'] ?? null;
        $amountKey   = $isDebit ? 'debited_amount' : 'credited_amount';
        $rawAmount   = isset($firstEntry[$amountKey]) ? (float) $firstEntry[$amountKey] : null;
        $occurredAt  = $firstEntry['creation_time'] ?? $payload['sent_at'] ?? null;

        if ($balanceId === null || $rawAmount === null || $occurredAt === null) {
            $this->logger->warning('[Wise] cards#tx: missing required field(s), skipping.');

            return null;
        }

        $txAmount     = $data['transaction_amount'] ?? [];
        $txCurrency   = isset($txAmount['currency']) ? (string) $txAmount['currency'] : null;
        $txValue      = isset($txAmount['value']) ? abs((float) $txAmount['value']) : null;
        $signedAmount = $isDebit ? -abs($rawAmount) : abs($rawAmount);

        // ── Build rich note ─────────────────────────────────────────────────
        $merchant     = $data['merchant'] ?? [];
        $merchantName = is_array($merchant) ? trim((string) ($merchant['name'] ?? '')) : '';
        $location     = is_array($merchant) ? ($merchant['location'] ?? []) : [];
        $city         = is_array($location) ? trim((string) ($location['city'] ?? '')) : '';
        $country      = is_array($location) ? trim((string) ($location['country'] ?? '')) : '';
        $rate         = $firstEntry['rate'] ?? null;

        // Determine balance currency from the entry (debited_amount is in balance currency)
        // The txCurrency is the merchant currency; if they differ, include FX info.
        $balCurrency  = $txCurrency; // default: same currency
        // If rate exists in the entry, it means a conversion happened
        if ($rate !== null && $rate != 1.0) {
            // Balance currency is different from tx currency — we know the balance currency
            // from the account (looked up later), but for note purposes we can compute it:
            // debited_amount is in balance currency, transaction_amount is in merchant currency.
            // We'll use the account's known currency from the balance context.
            $balCurrency = null; // will be filled from the account's currency if available
        }

        $noteParts = [];

        // 1. Merchant name
        if ($merchantName !== '') {
            $noteParts[] = $merchantName;
        }

        // 2. City (only if not already part of merchant name)
        if ($city !== '' && ($merchantName === '' || !str_contains(strtolower($merchantName), strtolower($city)))) {
            $noteParts[] = $city;
        } elseif ($merchantName === '' && $country !== '') {
            $noteParts[] = $country;
        }

        // 3. Exchange rate info (cross-currency only)
        if ($rate !== null && $rate != 1.0 && $txValue !== null && $txCurrency !== null) {
            $noteParts[] = sprintf(
                '%s %s → %s @ %s',
                number_format($txValue, 2, '.', ''),
                $txCurrency,
                number_format(abs($rawAmount), 2, '.', ''),
                round((float) $rate, 4),
            );
        }

        $note = implode(' · ', $noteParts);

        $this->logger->info('[Wise] cards#tx: parsed → balance_id={bid} amount={amt} note="{note}"', [
            'bid'  => $balanceId,
            'amt'  => $signedAmount,
            'note' => $note,
        ]);

        return new DraftTransactionData(
            externalAccountId: (string) $balanceId,
            amount: $signedAmount,
            executedAt: new DateTimeImmutable((string) $occurredAt),
            note: $note,
            currency: $txCurrency,
        );
    }

    /**
     * Parses balances#update (all versions) and balances#credit v2.0.0.
     * All use the same flat data structure with snake_case fields.
     *
     * CARD channel transactions are processed here as a reliable fallback.
     * If cards#transaction-state-change also fires for the same tx, the
     * upstream duplicate detection will discard the second draft.
     */
    private function parseFlatPayload(array $data): ?DraftTransactionData
    {
        $channel = strtoupper(trim((string) ($data['channel_name'] ?? '')));

        $resource = $data['resource'] ?? [];
        if (!is_array($resource)) {
            $resource = [];
        }

        $balanceId = $data['balance_id'] ?? $resource['id'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $currency = isset($data['currency']) ? (string) $data['currency'] : null;
        $occurredAt = $data['occurred_at'] ?? null;

        if ($balanceId === null || $amount === null || $currency === null || $occurredAt === null) {
            $this->logger->warning('[Wise] balances#update: missing required field(s) — balance_id={bid} amount={amt} currency={cur} occurred_at={ts}', [
                'bid' => $balanceId ?? 'null',
                'amt' => $amount ?? 'null',
                'cur' => $currency ?? 'null',
                'ts'  => $occurredAt ?? 'null',
            ]);

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

        $this->logger->info('[Wise] balances#update parsed: channel={channel} balance_id={bid} amount={amt} {currency} note="{note}"', [
            'channel' => $channel ?: 'n/a',
            'bid'     => $balanceId,
            'amt'     => $signedAmount,
            'currency' => $currency,
            'note'    => $note,
        ]);

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

        // Map of event → required schema version.
        // balances#update needs 3.0.0 (omits balance_id in 2.0.0).
        //
        // NOTE: cards#transaction-state-change is application-level only
        // (not available for profile/personal-token subscriptions).
        // Card transactions are captured via balances#update (CARD channel)
        // and enriched via Activities API polling.
        // If cards#transaction-state-change is ever registered manually via
        // Wise UI, parseWebhookPayload() will still handle it.
        $requiredVersions = [
            self::WEBHOOK_TRIGGER_UPDATE => self::WEBHOOK_DELIVERY_VERSION,
        ];
        $eventsToRegister = array_keys($requiredVersions);

        foreach ($subscriptions as $subscription) {
            $event    = $subscription['trigger_on'] ?? null;
            $delivery = $subscription['delivery'] ?? [];
            if (!is_string($event) || !is_array($delivery) || ($delivery['url'] ?? null) !== $webhookUrl) {
                continue;
            }

            // Only manage events we own.
            if (!array_key_exists($event, $requiredVersions)) {
                continue;
            }

            $existingVersion = (string) ($delivery['version'] ?? '');
            if ($existingVersion !== $requiredVersions[$event]) {
                // Wrong schema version — delete so we can recreate with the correct version.
                $subId = $subscription['id'] ?? null;
                if ($subId !== null) {
                    try {
                        $this->wiseClient->request('DELETE', "/v3/profiles/{$profileId}/subscriptions/{$subId}")->getContent();
                    } catch (\Throwable) {
                        // Best-effort; if delete fails we still try to create the new one below.
                    }
                }
                // Leave the event in $eventsToRegister so a fresh sub gets created.
                continue;
            }

            // Correct version already exists — no need to register this event.
            $eventsToRegister = array_values(array_filter($eventsToRegister, fn(string $e) => $e !== $event));
        }

        foreach ($eventsToRegister as $event) {
            try {
                $this->wiseClient->request('POST', "/v3/profiles/{$profileId}/subscriptions", [
                    'json' => [
                        'name'       => sprintf('Budget Wise %s', $event),
                        'trigger_on' => $event,
                        'delivery'   => [
                            'version' => $requiredVersions[$event],
                            'url'     => $webhookUrl,
                        ],
                    ],
                ])->getContent();
            } catch (HttpExceptionInterface | TransportExceptionInterface $e) {
                if ($e instanceof HttpExceptionInterface) {
                    $message = $this->formatWiseHttpError('registerWebhook(create)', $e);
                    // 403 = token lacks permission for this event type (e.g. app-level-only events
                    // on a personal token). Log and continue — don't block other registrations.
                    if ($e->getResponse()->getStatusCode() === 403) {
                        $this->logger->warning('[Wise] {msg} — skipping, register manually in Wise UI if needed.', [
                            'msg'   => $message,
                            'event' => $event,
                        ]);
                        continue;
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
     * Builds a note from a flat balances#update / balances#credit-v2 payload.
     *
     * Only includes REAL data (merchant name, bank description, meaningful reference).
     * Returns empty string when no meaningful data is available — the transaction
     * already carries account, category, amount, and direction, so labels like
     * "Card payment" or "Balance transfer" are redundant.
     */
    private function buildNoteFromFlatData(array $data): string
    {
        $description  = trim((string) ($data['description'] ?? ''));
        $reference    = trim((string) ($data['transfer_reference'] ?? ''));
        $merchant     = $data['merchant'] ?? [];
        $merchantName = is_array($merchant) ? trim((string) ($merchant['name'] ?? '')) : '';

        if ($merchantName !== '') {
            return $merchantName;
        }

        if ($description !== '') {
            return $description;
        }

        if ($this->usableReference($reference)) {
            return $reference;
        }

        return '';
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
