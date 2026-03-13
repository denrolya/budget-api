<?php

namespace App\Tests\Bank;

use App\Bank\DTO\BankAccountData;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\Provider\WiseProvider;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\Cache\ItemInterface as CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for WiseProvider.
 * No HTTP or DB interaction — all external calls are mocked.
 *
 * @group bank
 */
class WiseProviderTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject&HttpClientInterface $http;
    private \PHPUnit\Framework\MockObject\MockObject&CacheInterface $cache;
    private WiseProvider $provider;

    protected function setUp(): void
    {
        $this->http  = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->provider = new WiseProvider(
            wiseClient: $this->http,
            cache: $this->cache,
            baseCurrency: 'EUR',
            allowedCurrencies: ['EUR', 'USD', 'UAH'],
        );
    }

    // -------------------------------------------------------------------------
    // fetchAccounts
    // -------------------------------------------------------------------------

    public function testFetchAccountsMapsBalancesToAccountData(): void
    {
        $profilesBody = json_encode([
            ['id' => 100, 'type' => 'personal'],
        ]);
        $balancesBody = json_encode([
            [
                'id'         => 555,
                'totalWorth' => ['currency' => 'EUR', 'value' => 1234.56],
            ],
            [
                'id'     => 556,
                'amount' => ['currency' => 'USD', 'value' => 99.00],  // no totalWorth fallback
            ],
        ]);

        $this->http
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($balancesBody),
            );

        $result = $this->provider->fetchAccounts([]);

        self::assertCount(2, $result);

        self::assertInstanceOf(BankAccountData::class, $result[0]);
        self::assertSame('555', $result[0]->externalId);
        self::assertSame('EUR', $result[0]->currency);
        self::assertEqualsWithDelta(1234.56, $result[0]->balance, 0.01);
        self::assertStringContainsString('EUR', $result[0]->name);

        self::assertSame('556', $result[1]->externalId);
        self::assertSame('USD', $result[1]->currency);
        self::assertEqualsWithDelta(99.0, $result[1]->balance, 0.01);
    }

    public function testFetchAccountsSkipsEntriesWithNullCurrency(): void
    {
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $balancesBody = json_encode([
            ['id' => 1, 'totalWorth' => ['value' => 100.0]],          // no currency
        ]);

        $this->http
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($balancesBody),
            );

        self::assertSame([], $this->provider->fetchAccounts([]));
    }

    public function testFetchAccountsWrapsHttpExceptionOnProfilesCallAsRuntimeException(): void
    {
        $this->http->method('request')->willThrowException($this->createHttpException());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/profiles/i');

        $this->provider->fetchAccounts([]);
    }

    public function testFetchAccountsWrapsHttpExceptionOnBalancesCallAsRuntimeException(): void
    {
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);

        $this->http
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->throwException($this->createHttpException()),
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/fetchAccounts failed/i');

        $this->provider->fetchAccounts([]);
    }

    // -------------------------------------------------------------------------
    // getPersonalProfileId — profile discovery
    // -------------------------------------------------------------------------

    public function testPersonalProfileIsSelectedOverOtherTypes(): void
    {
        $profilesBody = json_encode([
            ['id' => 10, 'type' => 'business'],
            ['id' => 20, 'type' => 'personal'],
        ]);
        $balancesBody = json_encode([]);

        $this->http
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($balancesBody),
            );

        // The balances URL must embed profile ID 20 (personal)
        $this->http
            ->expects(self::exactly(2))
            ->method('request')
            ->with(
                self::anything(),
                self::callback(fn(string $url) => !str_contains($url, '/v4/') || str_contains($url, '/20/')),
                self::anything(),
            );

        $this->provider->fetchAccounts([]);
    }

    public function testFallsBackToFirstProfileWhenNoPersonalType(): void
    {
        $profilesBody = json_encode([
            ['id' => 42, 'type' => 'business'],
        ]);
        $balancesBody = json_encode([]);

        $this->http
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($balancesBody),
            );

        // Should not throw — uses the fallback profile id
        $result = $this->provider->fetchAccounts([]);

        self::assertSame([], $result);
    }

    public function testThrowsWhenNoProfilesExist(): void
    {
        $this->http
            ->method('request')
            ->willReturn($this->mockResponse(json_encode([])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no profile found/i');

        $this->provider->fetchAccounts([]);
    }

    // -------------------------------------------------------------------------
    // fetchExchangeRates / getLatest / fetchRates
    // -------------------------------------------------------------------------

    public function testFetchExchangeRatesReturnsFormattedRates(): void
    {
        $apiBody = json_encode([
            ['source' => 'EUR', 'target' => 'USD', 'rate' => 1.08],
            ['source' => 'EUR', 'target' => 'UAH', 'rate' => 42.0],
            ['source' => 'EUR', 'target' => 'GBP', 'rate' => 0.85],  // GBP not in allowedCurrencies
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));
        $this->makeCacheCallThrough();

        $rates = $this->provider->fetchExchangeRates([]);

        self::assertIsArray($rates);
        // Base currency is always present and equals 1.0
        self::assertArrayHasKey('EUR', $rates);
        self::assertEqualsWithDelta(1.0, $rates['EUR'], 0.0001);

        self::assertArrayHasKey('USD', $rates);
        self::assertEqualsWithDelta(1.08, $rates['USD'], 0.0001);

        self::assertArrayHasKey('UAH', $rates);
        self::assertEqualsWithDelta(42.0, $rates['UAH'], 0.0001);

        // GBP should be excluded (not in allowedCurrencies)
        self::assertArrayNotHasKey('GBP', $rates);
    }

    public function testFetchExchangeRatesWrapsHttpExceptionAsRuntimeException(): void
    {
        $this->http->method('request')->willThrowException($this->createHttpException());
        $this->makeCacheCallThrough();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rates API error/i');

        $this->provider->fetchExchangeRates([]);
    }

    // -------------------------------------------------------------------------
    // getHistorical
    // -------------------------------------------------------------------------

    public function testGetHistoricalPassesTimeParamToRatesEndpoint(): void
    {
        $date    = CarbonImmutable::parse('2025-01-15');
        $apiBody = json_encode([
            ['source' => 'EUR', 'target' => 'USD', 'rate' => 1.05],
        ]);

        $this->http
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                '/v1/rates',
                self::callback(fn(array $opts) => isset($opts['query']['time'])
                    && str_starts_with($opts['query']['time'], '2025-01-15')
                    && ($opts['query']['source'] ?? '') === 'EUR'),
            )
            ->willReturn($this->mockResponse($apiBody));

        $this->makeCacheCallThrough();

        $rates = $this->provider->getHistorical($date);

        self::assertArrayHasKey('USD', $rates);
        self::assertEqualsWithDelta(1.05, $rates['USD'], 0.0001);
    }

    public function testGetHistoricalCacheKeyContainsDate(): void
    {
        $date    = CarbonImmutable::parse('2024-06-01');
        $apiBody = json_encode([]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(
                self::stringContains('2024-06-01'),
                self::anything(),
            )
            ->willReturnCallback(fn(string $key, callable $cb) => $cb($cacheItem));

        $this->provider->getHistorical($date);
    }

    // -------------------------------------------------------------------------
    // getRates
    // -------------------------------------------------------------------------

    public function testGetRatesWithNullDelegatesToGetLatest(): void
    {
        $apiBody = json_encode([
            ['source' => 'EUR', 'target' => 'USD', 'rate' => 1.08],
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(
                self::stringContains('wise.latest.'),
                self::anything(),
            )
            ->willReturnCallback(fn(string $key, callable $cb) => $cb($cacheItem));

        $rates = $this->provider->getRates(null);

        self::assertArrayHasKey('USD', $rates);
    }

    public function testGetRatesWithTodayDelegatesToGetLatest(): void
    {
        $apiBody = json_encode([]);
        $this->http->method('request')->willReturn($this->mockResponse($apiBody));

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::stringContains('wise.latest.'), self::anything())
            ->willReturnCallback(fn(string $key, callable $cb) => $cb($cacheItem));

        $this->provider->getRates(CarbonImmutable::today());
    }

    public function testGetRatesWithPastDateDelegatesToGetHistorical(): void
    {
        $pastDate = CarbonImmutable::parse('2020-01-01');
        $apiBody  = json_encode([]);
        $this->http->method('request')->willReturn($this->mockResponse($apiBody));

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::stringContains('wise.historical.'), self::anything())
            ->willReturnCallback(fn(string $key, callable $cb) => $cb($cacheItem));

        $this->provider->getRates($pastDate);
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    public function testParseWebhookPayloadMapsBalancesUpdateCredit(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'balances#credit',
            'data' => [
                'resource' => ['id' => 111],
                'balance_id' => 222,
                'amount' => 70,
                'currency' => 'GBP',
                'transaction_type' => 'credit',
                'channel_name' => 'TRANSFER',
                'transfer_reference' => 'BNK-1234567',
                'occurred_at' => '2023-03-08T14:55:38Z',
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('222', $result->externalAccountId);
        self::assertEqualsWithDelta(70.0, $result->amount, 0.001);
        self::assertSame('GBP', $result->currency);
        self::assertStringContainsString('BNK-1234567', $result->note);
    }

    public function testParseWebhookPayloadMapsBalancesUpdateDebitAsNegative(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'balances#update',
            'data' => [
                'resource' => ['id' => 111],
                'amount' => 9.6,
                'currency' => 'GBP',
                'transaction_type' => 'debit',
                'occurred_at' => '2023-03-08T15:26:07Z',
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('111', $result->externalAccountId);
        self::assertEqualsWithDelta(-9.6, $result->amount, 0.001);
    }

    public function testParseWebhookPayloadMapsBalancesUpdateCreditViaUpdateEvent(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'balances#update',
            'data' => [
                'balance_id' => 333,
                'amount' => 50.0,
                'currency' => 'EUR',
                'transaction_type' => 'credit',
                'occurred_at' => '2023-03-09T10:00:00Z',
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('333', $result->externalAccountId);
        self::assertEqualsWithDelta(50.0, $result->amount, 0.001);
    }

    public function testParseWebhookPayloadReturnsNullForUnsupportedEvent(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'transfers#state-change',
            'data' => [],
        ]);

        self::assertNull($result);
    }

    public function testParseWebhookPayloadProcessesCardChannelInBalancesUpdate(): void
    {
        // balances#update for CARD channel must create a draft — cards#transaction-state-change
        // is not reliably delivered, so CARD channel events serve as the reliable fallback.
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'balances#update',
            'data' => [
                'balance_id'       => 89046937,
                'amount'           => 11482,
                'currency'         => 'HUF',
                'transaction_type' => 'debit',
                'channel_name'     => 'CARD',
                'occurred_at'      => '2026-03-12T18:37:10Z',
                'transfer_reference' => '738d5a70-f4a1-4942-f4c9-e4e9a06a13c7',
                'step_id'          => 9107113376,
            ],
        ]);

        self::assertNotNull($result);
        self::assertSame('89046937', $result->externalAccountId);
        self::assertSame(-11482.0, $result->amount);
        self::assertSame('HUF', $result->currency);
        self::assertSame('', $result->note); // empty — no merchant data in balances#update
    }

    public function testParseCardTransactionWebhookCreatesDraftWithMerchantName(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type'     => 'cards#transaction-state-change',
            'schema_version' => '2.1.0',
            'sent_at'        => '2026-03-12T18:37:10Z',
            'data'           => [
                'transaction_state'  => 'COMPLETED',
                'transaction_type'   => 'POS_PURCHASE',
                'transaction_amount' => ['value' => 11482.0, 'currency' => 'HUF'],
                'merchant'           => [
                    'name'     => 'TESCO BUDAPEST',
                    'location' => ['country' => 'Hungary', 'city' => 'Budapest'],
                ],
                'debits' => [
                    [
                        'balance_id'     => 89046937,
                        'debited_amount' => 11482.0,
                        'for_amount'     => 11482.0,
                        'rate'           => 1.0,
                        'fee'            => 0.0,
                        'creation_time'  => '2026-03-12T18:37:10Z',
                    ],
                ],
                'credits' => [],
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('89046937', $result->externalAccountId);
        self::assertEqualsWithDelta(-11482.0, $result->amount, 0.001);
        self::assertSame('HUF', $result->currency);
        self::assertSame('TESCO BUDAPEST', $result->note);
        self::assertSame('2026-03-12T18:37:10+00:00', $result->executedAt->format(\DateTimeInterface::ATOM));
    }

    public function testParseCardTransactionWebhookReturnsEmptyNoteWhenNoMerchant(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'cards#transaction-state-change',
            'sent_at'    => '2026-03-12T10:00:00Z',
            'data'       => [
                'transaction_state'  => 'COMPLETED',
                'transaction_type'   => 'CASH_WITHDRAWAL',
                'transaction_amount' => ['value' => 100.0, 'currency' => 'EUR'],
                'merchant'           => [],
                'debits' => [
                    ['balance_id' => 555, 'debited_amount' => 100.0, 'rate' => 1.0, 'creation_time' => '2026-03-12T10:00:00Z'],
                ],
                'credits' => [],
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('', $result->note); // empty — no merchant data, same currency
        self::assertEqualsWithDelta(-100.0, $result->amount, 0.001);
    }

    public function testParseCardTransactionWebhookIncludesExchangeRateForCrossCurrency(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type'     => 'cards#transaction-state-change',
            'schema_version' => '2.1.0',
            'sent_at'        => '2026-03-13T08:09:43Z',
            'data'           => [
                'transaction_state'  => 'COMPLETED',
                'transaction_type'   => 'POS_PURCHASE',
                'transaction_amount' => ['value' => 15.20, 'currency' => 'EUR'],
                'merchant'           => [
                    'name'     => 'Lidl',
                    'location' => ['country' => 'Hungary', 'city' => 'Budapest'],
                ],
                'debits' => [
                    [
                        'balance_id'     => 89046937,
                        'debited_amount' => 5692.0,
                        'rate'           => 374.4737,
                        'creation_time'  => '2026-03-13T08:09:43Z',
                    ],
                ],
                'credits' => [],
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('89046937', $result->externalAccountId);
        self::assertEqualsWithDelta(-5692.0, $result->amount, 0.001);
        self::assertSame('EUR', $result->currency);
        // Note should include merchant name, city, and exchange rate info
        self::assertStringContainsString('Lidl', $result->note);
        self::assertStringContainsString('Budapest', $result->note);
        self::assertStringContainsString('EUR', $result->note);
        self::assertStringContainsString('374.4737', $result->note);
    }

    public function testParseCardTransactionWebhookReturnsNullForNonCompleted(): void
    {
        foreach (['IN_PROGRESS', 'DECLINED', 'CANCELLED'] as $state) {
            $result = $this->provider->parseWebhookPayload([
                'event_type' => 'cards#transaction-state-change',
                'data'       => [
                    'transaction_state'  => $state,
                    'transaction_type'   => 'POS_PURCHASE',
                    'transaction_amount' => ['value' => 50.0, 'currency' => 'EUR'],
                    'debits' => [
                        ['balance_id' => 1, 'debited_amount' => 50.0, 'creation_time' => '2026-03-12T10:00:00Z'],
                    ],
                ],
            ]);

            self::assertNull($result, "Expected null for state={$state}");
        }
    }

    public function testParseCardTransactionWebhookHandlesCreditRefund(): void
    {
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'cards#transaction-state-change',
            'sent_at'    => '2026-03-12T12:00:00Z',
            'data'       => [
                'transaction_state'  => 'COMPLETED',
                'transaction_type'   => 'REFUND',
                'transaction_amount' => ['value' => 25.0, 'currency' => 'EUR'],
                'merchant'           => ['name' => 'AMAZON'],
                'debits'             => [],
                'credits' => [
                    ['balance_id' => 777, 'credited_amount' => 25.0, 'creation_time' => '2026-03-12T12:00:00Z'],
                ],
            ],
        ]);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('777', $result->externalAccountId);
        self::assertEqualsWithDelta(25.0, $result->amount, 0.001); // positive = income (refund)
        self::assertSame('AMAZON', $result->note);
    }

    public function testParseCardTransactionWebhookReturnsNullWhenNoDebitsOrCredits(): void
    {
        // v2.0.0 payload without debits/credits array → cannot match account.
        $result = $this->provider->parseWebhookPayload([
            'event_type' => 'cards#transaction-state-change',
            'data'       => [
                'transaction_state'  => 'COMPLETED',
                'transaction_type'   => 'POS_PURCHASE',
                'transaction_amount' => ['value' => 50.0, 'currency' => 'EUR'],
            ],
        ]);

        self::assertNull($result);
    }

    public function testRegisterWebhookSkipsCreateWhenBothSubscriptionsExist(): void
    {
        // Both balances#update (3.0.0) and cards#transaction-state-change (2.1.0) already exist
        // → no POSTs, only the two GETs.
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([
            [
                'id'         => 'abc-123',
                'trigger_on' => 'balances#update',
                'delivery'   => ['version' => '3.0.0', 'url' => 'https://example.com/api/webhooks/wise'],
            ],
            [
                'id'         => 'def-456',
                'trigger_on' => 'cards#transaction-state-change',
                'delivery'   => ['version' => '2.1.0', 'url' => 'https://example.com/api/webhooks/wise'],
            ],
        ]);

        $this->http
            ->expects(self::exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($subscriptionsBody),
            );

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
    }

    public function testRegisterWebhookSkipsCreateWhenCorrectSubscriptionAlreadyExists(): void
    {
        // balances#update 3.0.0 already exists; cards#transaction-state-change does not
        // → 1 POST for the card event.
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([
            [
                'id'         => 'abc-123',
                'trigger_on' => 'balances#update',
                'delivery'   => ['version' => '3.0.0', 'url' => 'https://example.com/api/webhooks/wise'],
            ],
        ]);

        $this->http
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($subscriptionsBody),
                $this->mockResponse('{}'),  // POST cards#transaction-state-change 2.1.0
            );

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
    }

    public function testRegisterWebhookReplacesStaleSchemaSubscription(): void
    {
        // Existing balances#update subscription uses 2.0.0 (wrong) — expect DELETE + POST.
        // cards#transaction-state-change is also missing → 1 more POST.
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([
            [
                'id'         => 'stale-sub-id',
                'trigger_on' => 'balances#update',
                'delivery'   => ['version' => '2.0.0', 'url' => 'https://example.com/api/webhooks/wise'],
            ],
        ]);

        $this->http
            ->expects(self::exactly(5))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),     // GET /v2/profiles
                $this->mockResponse($subscriptionsBody), // GET subscriptions
                $this->mockResponse(''),                 // DELETE stale balances#update sub
                $this->mockResponse('{}'),               // POST balances#update 3.0.0
                $this->mockResponse('{}'),               // POST cards#transaction-state-change 2.1.0
            );

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
    }

    public function testRegisterWebhookCreatesSubscriptionWhenMissing(): void
    {
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([]);

        $this->http
            ->expects(self::exactly(4))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($subscriptionsBody),
                $this->mockResponse('{}'), // POST balances#update
                $this->mockResponse('{}'), // POST cards#transaction-state-change
            );

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
    }

    public function testRegisterWebhookCreatesBalancesUpdateSubscriptionWith300(): void
    {
        // Verify that the balances#update POST uses schema version 3.0.0.
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([
            // Only card sub exists; balances#update is missing.
            [
                'id'         => 'def-456',
                'trigger_on' => 'cards#transaction-state-change',
                'delivery'   => ['version' => '2.1.0', 'url' => 'https://example.com/api/webhooks/wise'],
            ],
        ]);

        $capturedJson = null;
        $this->http
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options = []) use (&$capturedJson, $profilesBody, $subscriptionsBody) {
                if ($method === 'POST') {
                    $capturedJson = $options['json'] ?? null;
                }
                if (str_contains($url, '/v2/profiles')) {
                    return $this->mockResponse($profilesBody);
                }
                if ($method === 'GET') {
                    return $this->mockResponse($subscriptionsBody);
                }
                return $this->mockResponse('{}');
            });

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');

        self::assertSame('balances#update', $capturedJson['trigger_on'] ?? null);
        self::assertSame('3.0.0', $capturedJson['delivery']['version'] ?? null);
    }

    public function testRegisterWebhookCreatesCardSubscriptionWith210(): void
    {
        // Verify that the cards#transaction-state-change POST uses schema version 2.1.0.
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([
            // Only balances#update exists; card sub is missing.
            [
                'id'         => 'abc-123',
                'trigger_on' => 'balances#update',
                'delivery'   => ['version' => '3.0.0', 'url' => 'https://example.com/api/webhooks/wise'],
            ],
        ]);

        $capturedJson = null;
        $this->http
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options = []) use (&$capturedJson, $profilesBody, $subscriptionsBody) {
                if ($method === 'POST') {
                    $capturedJson = $options['json'] ?? null;
                }
                if (str_contains($url, '/v2/profiles')) {
                    return $this->mockResponse($profilesBody);
                }
                if ($method === 'GET') {
                    return $this->mockResponse($subscriptionsBody);
                }
                return $this->mockResponse('{}');
            });

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');

        self::assertSame('cards#transaction-state-change', $capturedJson['trigger_on'] ?? null);
        self::assertSame('2.1.0', $capturedJson['delivery']['version'] ?? null);
    }

    public function testRegisterWebhookThrowsLogicExceptionOn403(): void
    {
        $profilesBody      = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([]);

        // Build a real ResponseInterface mock that returns 403 and throws on getContent(true)
        $forbiddenResponse = $this->createMock(ResponseInterface::class);
        $forbiddenResponse->method('getStatusCode')->willReturn(403);
        $forbiddenResponse->method('getContent')->willReturnCallback(
            function (bool $throw = true) use (&$httpException) {
                if ($throw) {
                    throw $httpException;
                }
                return '{"error":"unauthorized"}';
            }
        );

        $httpException = new class($forbiddenResponse) extends \RuntimeException implements HttpExceptionInterface {
            public function __construct(private ResponseInterface $r) { parent::__construct('HTTP/2 403'); }
            public function getResponse(): ResponseInterface { return $this->r; }
        };

        // GET profiles + GET subscriptions (empty) + POST balances#update (→ 403, throws immediately).
        // The card event POST is never reached because the exception is thrown first.
        $this->http
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($subscriptionsBody),
                $forbiddenResponse,
            );

        $this->expectException(\LogicException::class);
        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockResponse(string $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn($body);

        return $response;
    }

    /** Make cache->get() transparently call through to the callback (bypasses actual caching). */
    private function makeCacheCallThrough(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $this->cache
            ->method('get')
            ->willReturnCallback(fn(string $key, callable $cb) => $cb($cacheItem));
    }

    private function createHttpException(): HttpExceptionInterface
    {
        return new class extends \RuntimeException implements HttpExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                throw new \LogicException('not implemented');
            }
        };
    }
}
