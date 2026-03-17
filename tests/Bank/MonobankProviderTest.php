<?php

declare(strict_types=1);

namespace App\Tests\Bank;

use App\Bank\DTO\BankAccountData;
use App\Bank\Provider\MonobankProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface as CacheItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for MonobankProvider (methods other than parseWebhookPayload,
 * which is covered in MonobankProviderParseWebhookPayloadTest).
 *
 * @group bank
 */
class MonobankProviderTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject&HttpClientInterface $http;
    private \PHPUnit\Framework\MockObject\MockObject&CacheInterface $cache;
    private MonobankProvider $provider;

    protected function setUp(): void
    {
        $this->http = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->provider = new MonobankProvider(
            monobankClient: $this->http,
            cache: $this->cache,
            monobankApiKey: 'test_key',
            baseCurrency: 'EUR',
            allowedCurrencies: ['EUR', 'USD', 'UAH'],
        );
    }

    // -------------------------------------------------------------------------
    // fetchAccounts
    // -------------------------------------------------------------------------

    public function testFetchAccountsMapsResponseToAccountData(): void
    {
        $body = json_encode([
            'accounts' => [
                [
                    'id' => 'acc_abc',
                    'maskedPan' => ['4111 **** **** 1234'],
                    'currencyCode' => 980,   // UAH
                    'balance' => 150000, // 1500.00 UAH
                ],
                [
                    'id' => 'acc_def',
                    'maskedPan' => [],
                    'currencyCode' => 840,   // USD
                    'balance' => 5000,  // 50.00 USD
                ],
            ],
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($body));

        $result = $this->provider->fetchAccounts([]);

        self::assertCount(2, $result);

        self::assertInstanceOf(BankAccountData::class, $result[0]);
        self::assertSame('acc_abc', $result[0]->externalId);
        self::assertSame('UAH', $result[0]->currency);
        self::assertEqualsWithDelta(1500.0, $result[0]->balance, 0.001);
        self::assertStringContainsString('4111', $result[0]->name);

        self::assertSame('acc_def', $result[1]->externalId);
        self::assertSame('USD', $result[1]->currency);
        self::assertEqualsWithDelta(50.0, $result[1]->balance, 0.001);
        // No maskedPan — falls back to "Monobank USD"
        self::assertStringContainsString('Monobank', $result[1]->name);
    }

    public function testFetchAccountsReturnsEmptyArrayWhenNoAccounts(): void
    {
        $body = json_encode(['accounts' => []]);
        $this->http->method('request')->willReturn($this->mockResponse($body));

        self::assertSame([], $this->provider->fetchAccounts([]));
    }

    public function testFetchAccountsWrapsHttpExceptionAsRuntimeException(): void
    {
        $this->http->method('request')->willThrowException(
            $this->createHttpException(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/fetchAccounts failed/i');

        $this->provider->fetchAccounts([]);
    }

    // -------------------------------------------------------------------------
    // fetchExchangeRates / fetchRates (via getLatest)
    // -------------------------------------------------------------------------

    public function testFetchExchangeRatesReturnsCrossRatesRelativeToBase(): void
    {
        // EUR/UAH = 42.5, USD/UAH = 38.0 → USD relative to EUR = 42.5/38.0
        $apiBody = json_encode([
            ['currencyCodeA' => 978, 'currencyCodeB' => 980, 'rateCross' => 42.5],  // EUR/UAH
            ['currencyCodeA' => 840, 'currencyCodeB' => 980, 'rateCross' => 38.0],  // USD/UAH
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));
        $this->makeCacheCallThrough();

        $rates = $this->provider->fetchExchangeRates([]);

        self::assertIsArray($rates);
        self::assertArrayHasKey('EUR', $rates);
        self::assertArrayHasKey('USD', $rates);
        self::assertArrayHasKey('UAH', $rates);

        // EUR is base → always 1.0
        self::assertEqualsWithDelta(1.0, $rates['EUR'], 0.0001);
        // USD = 42.5 / 38.0
        self::assertEqualsWithDelta(42.5 / 38.0, $rates['USD'], 0.0001);
        // UAH = 42.5 / 1.0 = 42.5 (UAH rate to UAH = 1.0 internally)
        self::assertEqualsWithDelta(42.5, $rates['UAH'], 0.0001);
    }

    public function testFetchExchangeRatesFiltersOutNonAllowedCurrencies(): void
    {
        // HUF (348) is NOT in allowedCurrencies = ['EUR', 'USD', 'UAH']
        $apiBody = json_encode([
            ['currencyCodeA' => 978, 'currencyCodeB' => 980, 'rateCross' => 42.5],  // EUR/UAH
            ['currencyCodeA' => 348, 'currencyCodeB' => 980, 'rateCross' => 0.12],  // HUF/UAH
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));
        $this->makeCacheCallThrough();

        $rates = $this->provider->fetchExchangeRates([]);

        self::assertArrayNotHasKey('HUF', $rates);
    }

    public function testFetchExchangeRatesThrowsWhenBaseCurrencyRateUnavailable(): void
    {
        // No EUR/UAH pair → can't compute relative rates
        $apiBody = json_encode([
            ['currencyCodeA' => 840, 'currencyCodeB' => 980, 'rateCross' => 38.0],  // USD/UAH only
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));
        $this->makeCacheCallThrough();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/EUR.*not available/i');

        $this->provider->fetchExchangeRates([]);
    }

    public function testFetchExchangeRatesUsesBuyPlusSellAverageFallback(): void
    {
        // rateCross absent → falls back to average of rateBuy & rateSell
        $apiBody = json_encode([
            ['currencyCodeA' => 978, 'currencyCodeB' => 980, 'rateBuy' => 42.0, 'rateSell' => 43.0], // EUR avg=42.5
            ['currencyCodeA' => 840, 'currencyCodeB' => 980, 'rateBuy' => 37.0, 'rateSell' => 39.0], // USD avg=38.0
        ]);

        $this->http->method('request')->willReturn($this->mockResponse($apiBody));
        $this->makeCacheCallThrough();

        $rates = $this->provider->fetchExchangeRates([]);

        self::assertEqualsWithDelta(1.0, $rates['EUR'], 0.0001);
        self::assertEqualsWithDelta(42.5 / 38.0, $rates['USD'], 0.0001);
    }

    public function testFetchExchangeRatesWrapsHttpExceptionAsRuntimeException(): void
    {
        $this->http->method('request')->willThrowException($this->createHttpException());
        $this->makeCacheCallThrough();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/currency API error/i');

        $this->provider->fetchExchangeRates([]);
    }

    // -------------------------------------------------------------------------
    // registerWebhook
    // -------------------------------------------------------------------------

    public function testRegisterWebhookPostsCorrectPayload(): void
    {
        $webhookUrl = 'https://example.com/webhook/monobank';

        $this->http
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                '/personal/webhook',
                self::callback(static function (array $options) use ($webhookUrl) {
                    return ($options['headers']['X-Token'] ?? '') === 'test_key'
                        && ($options['json']['webHookUrl'] ?? '') === $webhookUrl;
                }),
            )
            ->willReturn($this->mockResponse('{}'));

        $this->provider->registerWebhook([], $webhookUrl);
    }

    public function testRegisterWebhookWrapsHttpExceptionAsRuntimeException(): void
    {
        $this->http->method('request')->willThrowException($this->createHttpException());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/registerWebhook failed/i');

        $this->provider->registerWebhook([], 'https://example.com/wh');
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
            ->willReturnCallback(static fn (string $key, callable $cb) => $cb($cacheItem));
    }

    private function createHttpException(): HttpExceptionInterface
    {
        return new class extends RuntimeException implements HttpExceptionInterface {
            public function getResponse(): ResponseInterface
            {
                // In tests we never call this; just satisfies the interface.
                throw new LogicException('not implemented');
            }
        };
    }
}
