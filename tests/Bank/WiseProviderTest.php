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

    public function testRegisterWebhookSkipsCreateWhenMatchingSubscriptionAlreadyExists(): void
    {
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([
            [
                'trigger_on' => 'balances#update',
                'delivery' => [
                    'version' => '2.0.0',
                    'url' => 'https://example.com/api/webhooks/wise',
                ],
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

    public function testRegisterWebhookCreatesSubscriptionWhenMissing(): void
    {
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([]);

        $this->http
            ->expects(self::exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($subscriptionsBody),
                $this->mockResponse('{}'),
            );

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
    }

    public function testRegisterWebhookCreatesBalancesUpdateSubscription(): void
    {
        $profilesBody = json_encode([['id' => 1, 'type' => 'personal']]);
        $subscriptionsBody = json_encode([]);

        $this->http
            ->expects(self::exactly(3))
            ->method('request')
            ->with(
                self::logicalOr(
                    self::equalTo('GET'),
                    self::equalTo('POST')
                ),
                self::logicalOr(
                    self::equalTo('/v2/profiles'),
                    self::equalTo('/v3/profiles/1/subscriptions')
                ),
                self::callback(function (mixed $options): bool {
                    if (!is_array($options) || !isset($options['json'])) {
                        return true;
                    }

                    return ($options['json']['trigger_on'] ?? null) === 'balances#update';
                })
            )
            ->willReturnOnConsecutiveCalls(
                $this->mockResponse($profilesBody),
                $this->mockResponse($subscriptionsBody),
                $this->mockResponse('{}'),
            );

        $this->provider->registerWebhook([], 'https://example.com/api/webhooks/wise');
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
