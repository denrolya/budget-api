<?php

declare(strict_types=1);

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
use Exception;
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
use Throwable;

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
    private const WEBHOOK_TRIGGER_UPDATE = 'balances#update';              // any balance change (credit or debit)
    private const WEBHOOK_TRIGGER_CREDIT = 'balances#credit';              // money credited to balance
    private const WEBHOOK_TRIGGER_CARD_TX = 'cards#transaction-state-change'; // card tx with merchant data
    private const WEBHOOK_DELIVERY_VERSION = '3.0.0';

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
     *
     * @throws Exception
     */
    public function fetchAccounts(array $credentials): array
    {
        $profileId = $this->getPersonalProfileId();

        $balances = $this->requestJson('GET', "/v4/profiles/{$profileId}/balances?types=STANDARD", 'fetchAccounts');

        $result = [];
        foreach ($balances as $balanceEntry) {
            assert(\is_array($balanceEntry));
            $totalWorth = \is_array($balanceEntry['totalWorth'] ?? null) ? $balanceEntry['totalWorth'] : [];
            $amountData = \is_array($balanceEntry['amount'] ?? null) ? $balanceEntry['amount'] : [];
            $currency = $totalWorth['currency'] ?? $amountData['currency'] ?? null;
            $rawValue = $totalWorth['value'] ?? $amountData['value'] ?? 0;
            assert(is_numeric($rawValue));
            $amountValue = (float) $rawValue;

            if (null === $currency) {
                continue;
            }

            assert(is_scalar($balanceEntry['id'] ?? ''));
            assert(\is_string($currency));

            $result[] = new BankAccountData(
                externalId: (string) $balanceEntry['id'],
                name: \sprintf('Wise %s', $currency),
                currency: $currency,
                balance: $amountValue,
            );
        }

        return $result;
    }

    /**
     * Returns latest rates relative to $baseCurrency. Cached 24 h.
     *
     * @return array<string, float>
     *
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
        $profileId = $this->getPersonalProfileId();
        $balanceCurrency = $this->getBalanceCurrency($profileId, $externalAccountId);

        if (null === $balanceCurrency) {
            $this->logger->warning('[Wise] fetchTransactions: cannot resolve currency for balance {bid}', [
                'bid' => $externalAccountId,
            ]);

            return [];
        }

        $results = [];
        $cursor = null;

        do {
            $query = [
                'since' => $from->format('c'),
                'until' => $to->format('c'),
                'status' => 'COMPLETED',
                'size' => 100,
            ];
            if (null !== $cursor) {
                $query['nextCursor'] = $cursor;
            }

            try {
                $page = $this->requestJson('GET', "/v1/profiles/{$profileId}/activities", 'fetchTransactions', [
                    'query' => $query,
                ]);
            } catch (RuntimeException $e) {
                throw new RuntimeException("Wise fetchTransactions failed: {$e->getMessage()}", 0, $e);
            }

            $activities = \is_array($page['activities'] ?? null) ? $page['activities'] : [];
            $cursor = isset($page['cursor']) && \is_string($page['cursor']) ? $page['cursor'] : null;

            foreach ($activities as $activity) {
                assert(\is_array($activity));
                $parsed = $this->parseActivityAmount($activity);
                if (null === $parsed || $parsed['currency'] !== $balanceCurrency) {
                    continue;
                }

                $createdOn = $activity['createdOn'] ?? null;
                if (null === $createdOn) {
                    continue;
                }

                $signedValue = $this->resolveActivitySignedAmount(
                    $parsed['value'],
                    $parsed['stripped'],
                    strtoupper((string) ($activity['type'] ?? '')),
                );

                $title = strip_tags(trim((string) ($activity['title'] ?? '')));
                $description = trim((string) ($activity['description'] ?? ''));
                $note = '' !== $title ? $title : ('' !== $description ? $description : '');

                $this->logger->info('[Wise] activity: type={type} amount={amt} {cur} note="{note}" date={date}', [
                    'type' => $activity['type'] ?? '?',
                    'amt' => $signedValue,
                    'cur' => $parsed['currency'],
                    'note' => $note,
                    'date' => $createdOn,
                ]);

                assert(\is_string($createdOn));

                $results[] = new DraftTransactionData(
                    externalAccountId: $externalAccountId,
                    amount: $signedValue,
                    executedAt: new DateTimeImmutable($createdOn),
                    note: $note,
                    currency: $parsed['currency'],
                );
            }
        } while (null !== $cursor && [] !== $activities);

        $this->logger->info('[Wise] fetchTransactions: {n} activities for balance {bid} ({cur})', [
            'n' => \count($results),
            'bid' => $externalAccountId,
            'cur' => $balanceCurrency,
        ]);

        return $results;
    }

    /**
     * Parses the primaryAmount field of a Wise activity into its numeric value, currency, and stripped string.
     * Returns null when the format is unrecognised.
     *
     * @return array{value: float, currency: string, stripped: string}|null
     */
    private function parseActivityAmount(mixed $activity): ?array
    {
        if (!\is_array($activity)) {
            return null;
        }

        $primary = $activity['primaryAmount'] ?? null;
        $stripped = \is_string($primary) ? strip_tags($primary) : '';

        if ('' !== $stripped && preg_match('/([+-]?[\d,\.]+)\s+([A-Z]{3})/i', $stripped, $m)) {
            return [
                'value' => (float) str_replace(',', '', $m[1]),
                'currency' => strtoupper($m[2]),
                'stripped' => $stripped,
            ];
        }

        // Fallback for potential future object format
        if (\is_array($primary) && isset($primary['currency'], $primary['value'])) {
            return [
                'value' => (float) $primary['value'],
                'currency' => (string) $primary['currency'],
                'stripped' => '',
            ];
        }

        return null;
    }

    /**
     * Applies the correct sign to an activity amount.
     *
     * CARD_PAYMENT and outgoing TRANSFER have no explicit sign prefix → negate.
     * Incoming TRANSFER carries an explicit "+" (e.g. "<positive>+ 1,350,000 HUF</positive>") → keep positive.
     */
    private function resolveActivitySignedAmount(float $value, string $primaryStripped, string $activityType): float
    {
        $hasExplicitSign = (bool) preg_match('/^[+-]/', ltrim($primaryStripped));
        if (!$hasExplicitSign && \in_array($activityType, ['CARD_PAYMENT', 'TRANSFER'], true)) {
            return -abs($value);
        }

        return $value;
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

        foreach ($balances as $balanceEntry) {
            assert(\is_array($balanceEntry));
            if ((string) ($balanceEntry['id'] ?? '') === $balanceId) {
                $totalWorth = \is_array($balanceEntry['totalWorth'] ?? null) ? $balanceEntry['totalWorth'] : [];
                $amountData = \is_array($balanceEntry['amount'] ?? null) ? $balanceEntry['amount'] : [];

                return isset($totalWorth['currency']) ? (string) $totalWorth['currency']
                    : (isset($amountData['currency']) ? (string) $amountData['currency'] : null);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // WebhookCapableInterface
    // -------------------------------------------------------------------------

    public function parseWebhookPayload(array $payload): ?DraftTransactionData
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $schemaVersion = (string) ($payload['schema_version'] ?? 'unknown');

        $this->logger->info('[Wise] Webhook received: event={event} schema={schema}', [
            'event' => $eventType,
            'schema' => $schemaVersion,
        ]);

        // cards#transaction-state-change (v2.1.0) carries merchant name and balance_id.
        // This is the primary source for card purchase transactions.
        if (self::WEBHOOK_TRIGGER_CARD_TX === $eventType) {
            return $this->parseCardTransactionPayload($payload['data'] ?? [], $payload);
        }

        if (self::WEBHOOK_TRIGGER_CREDIT !== $eventType && self::WEBHOOK_TRIGGER_UPDATE !== $eventType) {
            $this->logger->info('[Wise] Unrecognised event type, skipping.', ['event' => $eventType]);

            return null;
        }

        $data = $payload['data'] ?? [];
        if (!\is_array($data)) {
            $this->logger->warning('[Wise] Payload data is not an array.', ['event' => $eventType]);

            return null;
        }

        // balances#credit v3.0.0 uses a completely different action/resource structure.
        // Detect it by the presence of data.action (not present in v2.0.0 or balances#update).
        if (self::WEBHOOK_TRIGGER_CREDIT === $eventType && isset($data['action']) && \is_array($data['action'])) {
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
        if (!\is_array($resource)) {
            $resource = [];
        }

        $balanceId = $action['account_id'] ?? null;
        $settledAmount = \is_array($resource['settled_amount'] ?? null) ? $resource['settled_amount'] : [];
        $rawSettledValue = $settledAmount['value'] ?? null;
        $amount = (null !== $rawSettledValue && is_numeric($rawSettledValue)) ? (float) $rawSettledValue : null;
        $rawCurrency = $settledAmount['currency'] ?? null;
        $currency = (null !== $rawCurrency && \is_string($rawCurrency)) ? $rawCurrency : null;
        $occurredAt = $payload['sent_at'] ?? null;

        if (null === $balanceId || null === $amount || null === $currency || null === $occurredAt) {
            return null;
        }

        assert(is_scalar($balanceId));
        assert(\is_string($occurredAt));

        $reference = trim((string) ($resource['reference'] ?? ''));
        $note = $this->usableReference($reference) ? $reference : 'Transfer received';

        return new DraftTransactionData(
            externalAccountId: (string) $balanceId,
            amount: abs($amount), // always positive; it's a credit event
            executedAt: new DateTimeImmutable($occurredAt),
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
        if (!\is_array($data)) {
            $this->logger->warning('[Wise] cards#tx: data is not an array.');

            return null;
        }

        $state = strtoupper((string) ($data['transaction_state'] ?? ''));
        $txType = strtolower((string) ($data['transaction_type'] ?? ''));

        $this->logger->info('[Wise] cards#tx: state={state} type={type}', [
            'state' => $state,
            'type' => $txType,
        ]);

        if ('COMPLETED' !== $state) {
            return null;
        }

        $debits = \is_array($data['debits'] ?? null) ? $data['debits'] : [];
        $credits = \is_array($data['credits'] ?? null) ? $data['credits'] : [];
        $isDebit = [] !== $debits;
        $entries = $isDebit ? $debits : $credits;

        if ([] === $entries) {
            $this->logger->warning('[Wise] cards#tx: no debits/credits — v2.0.0 payload, cannot match account.');

            return null;
        }

        $firstEntry = $entries[0];
        assert(\is_array($firstEntry));
        $balanceId = $firstEntry['balance_id'] ?? null;
        $amountKey = $isDebit ? 'debited_amount' : 'credited_amount';
        $rawEntryAmount = $firstEntry[$amountKey] ?? null;
        $rawAmount = (null !== $rawEntryAmount && is_numeric($rawEntryAmount)) ? (float) $rawEntryAmount : null;
        $occurredAt = $firstEntry['creation_time'] ?? $payload['sent_at'] ?? null;

        if (null === $balanceId || null === $rawAmount || null === $occurredAt) {
            $this->logger->warning('[Wise] cards#tx: missing required field(s), skipping.');

            return null;
        }

        $txAmount = \is_array($data['transaction_amount'] ?? null) ? $data['transaction_amount'] : [];
        $rawTxCurrency = $txAmount['currency'] ?? null;
        $txCurrency = (null !== $rawTxCurrency && \is_string($rawTxCurrency)) ? $rawTxCurrency : null;
        $rawTxValue = $txAmount['value'] ?? null;
        $txValue = (null !== $rawTxValue && is_numeric($rawTxValue)) ? abs((float) $rawTxValue) : null;
        $signedAmount = $isDebit ? -abs($rawAmount) : abs($rawAmount);

        $rate = $firstEntry['rate'] ?? null;
        $note = $this->buildCardTransactionNote($data, $rawAmount, $txValue, $txCurrency, $rate);

        $this->logger->info('[Wise] cards#tx: parsed → balance_id={bid} amount={amt} note="{note}"', [
            'bid' => $balanceId,
            'amt' => $signedAmount,
            'note' => $note,
        ]);

        assert(is_scalar($balanceId));
        assert(\is_string($occurredAt));

        return new DraftTransactionData(
            externalAccountId: (string) $balanceId,
            amount: $signedAmount,
            executedAt: new DateTimeImmutable($occurredAt),
            note: $note,
            currency: $txCurrency,
        );
    }

    /**
     * Builds a rich note for a cards#transaction-state-change payload.
     *
     * Format: "Merchant · City · {txValue} {txCurrency} → {rawAmount} @ {rate}"
     * Parts are omitted when absent or not meaningful (e.g. same-currency transactions omit the FX line).
     */
    private function buildCardTransactionNote(array $data, float $rawAmount, ?float $txValue, ?string $txCurrency, mixed $rate): string
    {
        $merchant = $data['merchant'] ?? [];
        $merchantName = \is_array($merchant) ? trim((string) ($merchant['name'] ?? '')) : '';
        $location = \is_array($merchant) ? ($merchant['location'] ?? []) : [];
        $city = \is_array($location) ? trim((string) ($location['city'] ?? '')) : '';
        $country = \is_array($location) ? trim((string) ($location['country'] ?? '')) : '';

        $noteParts = [];

        if ('' !== $merchantName) {
            $noteParts[] = $merchantName;
        }

        if ('' !== $city && ('' === $merchantName || !str_contains(strtolower($merchantName), strtolower($city)))) {
            $noteParts[] = $city;
        } elseif ('' === $merchantName && '' !== $country) {
            $noteParts[] = $country;
        }

        $numericRate = is_numeric($rate) ? (float) $rate : null;
        if (null !== $numericRate && 1.0 != $numericRate && null !== $txValue && null !== $txCurrency) {
            $noteParts[] = \sprintf(
                '%s %s → %s @ %s',
                number_format($txValue, 2, '.', ''),
                $txCurrency,
                number_format(abs($rawAmount), 2, '.', ''),
                round($numericRate, 4),
            );
        }

        return implode(' · ', $noteParts);
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
        if (!\is_array($resource)) {
            $resource = [];
        }

        $balanceId = $data['balance_id'] ?? $resource['id'] ?? null;
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $currency = isset($data['currency']) ? (string) $data['currency'] : null;
        $occurredAt = $data['occurred_at'] ?? null;

        if (null === $balanceId || null === $amount || null === $currency || null === $occurredAt) {
            $this->logger->warning('[Wise] balances#update: missing required field(s) — balance_id={bid} amount={amt} currency={cur} occurred_at={ts}', [
                'bid' => $balanceId ?? 'null',
                'amt' => $amount ?? 'null',
                'cur' => $currency ?? 'null',
                'ts' => $occurredAt ?? 'null',
            ]);

            return null;
        }

        $transactionType = strtolower((string) ($data['transaction_type'] ?? 'credit'));
        $signedAmount = $amount;
        if ('debit' === $transactionType) {
            $signedAmount = -abs($amount);
        } elseif ('credit' === $transactionType) {
            $signedAmount = abs($amount);
        }

        $note = $this->buildNoteFromFlatData($data);

        $this->logger->info('[Wise] balances#update parsed: channel={channel} balance_id={bid} amount={amt} {currency} note="{note}"', [
            'channel' => '' !== $channel ? $channel : 'n/a',
            'bid' => $balanceId,
            'amt' => $signedAmount,
            'currency' => $currency,
            'note' => $note,
        ]);

        assert(is_scalar($balanceId));
        assert(\is_string($occurredAt));

        return new DraftTransactionData(
            externalAccountId: (string) $balanceId,
            amount: $signedAmount,
            executedAt: new DateTimeImmutable($occurredAt),
            note: $note,
            currency: $currency,
        );
    }

    public function registerWebhook(array $credentials, string $webhookUrl): void
    {
        $profileId = $this->getPersonalProfileId();

        try {
            $response = $this->wiseClient->request('GET', "/v3/profiles/{$profileId}/subscriptions");
            $decoded = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            assert(\is_array($decoded));
            $subscriptions = $decoded;
        } catch (HttpExceptionInterface|TransportExceptionInterface|JsonException $e) {
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
        $eventsToRegister = $this->resolveEventsToRegister($subscriptions, $webhookUrl, $requiredVersions, $profileId);

        foreach ($eventsToRegister as $event) {
            try {
                $this->wiseClient->request('POST', "/v3/profiles/{$profileId}/subscriptions", [
                    'json' => [
                        'name' => \sprintf('Budget Wise %s', $event),
                        'trigger_on' => $event,
                        'delivery' => [
                            'version' => $requiredVersions[$event],
                            'url' => $webhookUrl,
                        ],
                    ],
                ])->getContent();
            } catch (HttpExceptionInterface|TransportExceptionInterface $e) {
                if ($e instanceof HttpExceptionInterface) {
                    $message = $this->formatWiseHttpError('registerWebhook(create)', $e);
                    // 403 = token lacks permission for this event type (e.g. app-level-only events
                    // on a personal token). Log and continue — don't block other registrations.
                    if (403 === $e->getResponse()->getStatusCode()) {
                        $this->logger->warning('[Wise] {msg} — skipping, register manually in Wise UI if needed.', [
                            'msg' => $message,
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

    /**
     * Determines which webhook events still need to be registered.
     * Deletes existing subscriptions whose schema version no longer matches (best-effort).
     *
     * @param array<string, string> $requiredVersions event → required schema version
     *
     * @return string[] events that still need a new subscription created
     */
    private function resolveEventsToRegister(array $subscriptions, string $webhookUrl, array $requiredVersions, int $profileId): array
    {
        $eventsToRegister = array_keys($requiredVersions);

        foreach ($subscriptions as $subscription) {
            assert(\is_array($subscription));
            $event = $subscription['trigger_on'] ?? null;
            $delivery = \is_array($subscription['delivery'] ?? null) ? $subscription['delivery'] : [];
            if (!\is_string($event) || ($delivery['url'] ?? null) !== $webhookUrl) {
                continue;
            }

            // Only manage events we own.
            if (!\array_key_exists($event, $requiredVersions)) {
                continue;
            }

            $existingVersion = (string) ($delivery['version'] ?? '');
            if ($existingVersion !== $requiredVersions[$event]) {
                // Wrong schema version — delete so we can recreate with the correct version.
                $subId = $subscription['id'] ?? null;
                if (null !== $subId) {
                    try {
                        $this->wiseClient->request('DELETE', "/v3/profiles/{$profileId}/subscriptions/{$subId}")->getContent();
                    } catch (Throwable) {
                        // Best-effort; if delete fails we still try to create the new one below.
                    }
                }
                continue;
            }

            // Correct version already exists — no need to register this event.
            $eventsToRegister = array_values(array_filter($eventsToRegister, static fn (string $e) => $e !== $event));
        }

        return $eventsToRegister;
    }

    // -------------------------------------------------------------------------
    // Exchange rate helpers (used by ExchangeRatesController and fetchExchangeRates)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, float>
     *
     * @throws InvalidArgumentException
     */
    private function getLatest(): array
    {
        $today = CarbonImmutable::now()->toDateString();

        return $this->cache->get("wise.latest.{$today}", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->fetchRates(['source' => $this->baseCurrency]);
        });
    }

    /**
     * @return array<string, float>
     *
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
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            assert(\is_array($decoded));
        } catch (HttpExceptionInterface $e) {
            throw new RuntimeException('Wise rates API error: ' . $e->getMessage(), 0, $e);
        } catch (JsonException $e) {
            throw new RuntimeException('Wise rates API returned non-JSON response (network block?): ' . $e->getMessage(), 0, $e);
        }

        $formatted = [$this->baseCurrency => 1.0];
        foreach ($decoded as $rateEntry) {
            assert(\is_array($rateEntry));
            if (\in_array($rateEntry['target'] ?? '', $this->allowedCurrencies, true)) {
                $formatted[(string) $rateEntry['target']] = (float) $rateEntry['rate'];
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
            $decoded = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            assert(\is_array($decoded));
        } catch (HttpExceptionInterface|TransportExceptionInterface|JsonException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw new RuntimeException($this->formatWiseHttpError('fetchProfiles', $e), 0, $e);
            }
            throw new RuntimeException('Wise: failed to fetch profiles: ' . $e->getMessage(), 0, $e);
        }

        $fallback = null;
        foreach ($decoded as $profileEntry) {
            assert(\is_array($profileEntry));
            $profileType = (string) ($profileEntry['type'] ?? '');
            if (0 === strcasecmp($profileType, 'personal')) {
                assert(is_numeric($profileEntry['id']));
                return (int) $profileEntry['id'];
            }
            if (null === $fallback && isset($profileEntry['id'])) {
                assert(is_numeric($profileEntry['id']));
                $profileIdValue = (int) $profileEntry['id'];
                if (0 !== $profileIdValue) {
                    $fallback = $profileIdValue;
                }
            }
        }

        if (null !== $fallback) {
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
        $description = trim((string) ($data['description'] ?? ''));
        $reference = trim((string) ($data['transfer_reference'] ?? ''));
        $merchant = $data['merchant'] ?? [];
        $merchantName = \is_array($merchant) ? trim((string) ($merchant['name'] ?? '')) : '';

        if ('' !== $merchantName) {
            return $merchantName;
        }

        if ('' !== $description) {
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
        if ('' === $reference) {
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

    /**
     * @return array<mixed>
     *
     * @throws RuntimeException on API errors
     */
    private function requestJson(string $method, string $url, string $operation, array $options = []): array
    {
        try {
            $response = $this->wiseClient->request($method, $url, $options);
            $decoded = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            assert(\is_array($decoded));

            return $decoded;
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
            } catch (Throwable) {
                $headers = [];
            }
        } catch (Throwable) {
            $status = null;
            $headers = [];
        }

        $approvalResult = strtoupper($this->firstHeader($headers, 'x-2fa-approval-result') ?? '');

        if (403 === $status && 'REJECTED' === $approvalResult) {
            return \sprintf(
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
        } catch (Throwable) {
        }

        return \sprintf('Wise %s failed: %s%s', $operation, $e->getMessage(), '' !== $body ? ' | ' . $body : '');
    }

    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 === strcasecmp((string) $key, $name)) {
                if (\is_array($value) && [] !== $value) {
                    return (string) $value[0];
                }

                return \is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
