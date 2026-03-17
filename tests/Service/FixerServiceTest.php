<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FixerService;
use Carbon\CarbonImmutable;
use JsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class FixerServiceTest extends TestCase
{
    private const ALLOWED_CURRENCIES = ['EUR', 'USD', 'HUF', 'UAH'];

    private const BASE_CURRENCY = 'EUR';

    private const RATES = ['EUR' => 1.0, 'USD' => 1.1, 'HUF' => 390.0, 'UAH' => 40.0];

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Returns a partially mocked FixerService with only getLatest / getHistorical stubbed.
     * Also initialises the allowedCurrencies property so convert() can iterate over it.
     *
     * @return FixerService&MockObject
     */
    private function makeServiceStub(array $rates = self::RATES): FixerService
    {
        /** @var FixerService&MockObject $service */
        $service = $this->getMockBuilder(FixerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLatest', 'getHistorical'])
            ->getMock();

        $service->method('getLatest')->willReturn($rates);
        $service->method('getHistorical')->willReturn($rates);

        // allowedCurrencies lives on the parent; set it via reflection so convert() can iterate
        $property = new ReflectionProperty(\App\Service\BaseExchangeRatesProvider::class, 'allowedCurrencies');
        $property->setAccessible(true);
        $property->setValue($service, array_keys($rates));

        return $service;
    }

    /**
     * Returns a fully constructed FixerService suitable for testing getLatest /
     * getHistorical / fetchRates (the real HTTP + cache wiring).
     *
     * @throws InvalidArgumentException
     */
    private function makeServiceWithDependencies(
        HttpClientInterface $httpClient,
        CacheInterface $cache,
        ?Security $security = null,
        array $allowedCurrencies = self::ALLOWED_CURRENCIES,
        string $baseCurrency = self::BASE_CURRENCY,
    ): FixerService {
        if (null === $security) {
            $security = $this->createStub(Security::class);
        }

        return new FixerService(
            $httpClient,
            'test-api-key',
            $cache,
            $security,
            $allowedCurrencies,
            $baseCurrency,
        );
    }

    /** @return CacheInterface&MockObject */
    private function makeCacheThatExecutesCallback(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            function (string $key, callable $callback) {
                $item = $this->createStub(ItemInterface::class);

                return $callback($item);
            },
        );

        return $cache;
    }

    // ---------------------------------------------------------------------------
    // getRates() — routing to getLatest vs getHistorical
    // ---------------------------------------------------------------------------

    public function testGetRatesWithNullDateCallsGetLatest(): void
    {
        $service = $this->makeServiceStub();
        $service->expects(self::once())->method('getLatest')->willReturn(self::RATES);
        $service->expects(self::never())->method('getHistorical');

        self::assertSame(self::RATES, $service->getRates(null));
    }

    public function testGetRatesWithTodayCallsGetLatest(): void
    {
        $service = $this->makeServiceStub();
        $service->expects(self::once())->method('getLatest')->willReturn(self::RATES);
        $service->expects(self::never())->method('getHistorical');

        self::assertSame(self::RATES, $service->getRates(CarbonImmutable::now()));
    }

    public function testGetRatesWithYesterdayCallsGetHistorical(): void
    {
        $yesterday = CarbonImmutable::yesterday();

        $service = $this->makeServiceStub();
        $service->expects(self::never())->method('getLatest');
        $service->expects(self::once())->method('getHistorical')->with($yesterday)->willReturn(self::RATES);

        self::assertSame(self::RATES, $service->getRates($yesterday));
    }

    /**
     * Regression: old code compared only ->day (day-of-month), so Jan 15 and Mar 15 were
     * treated as "today" when today is any 15th. Must route to getHistorical instead.
     */
    public function testGetRatesSameDayOfMonthDifferentMonthCallsGetHistorical(): void
    {
        $today = CarbonImmutable::now();
        $sameDayLastMonth = $today->subMonth();

        if ($sameDayLastMonth->month === $today->month) {
            self::markTestSkipped('subMonth() did not produce a prior month on this date.');
        }

        $service = $this->makeServiceStub();
        $service->expects(self::never())->method('getLatest');
        $service->expects(self::once())->method('getHistorical')->willReturn(self::RATES);

        $service->getRates($sameDayLastMonth);
    }

    public function testGetRatesWithFutureDateCallsGetHistorical(): void
    {
        $future = CarbonImmutable::now()->addMonth();

        $service = $this->makeServiceStub();
        $service->expects(self::never())->method('getLatest');
        $service->expects(self::once())->method('getHistorical')->willReturn(self::RATES);

        $service->getRates($future);
    }

    // ---------------------------------------------------------------------------
    // currencyExists()
    // ---------------------------------------------------------------------------

    public function testCurrencyExistsReturnsTrueForKnownCurrency(): void
    {
        $service = $this->makeServiceStub();

        self::assertTrue($service->currencyExists('EUR'));
        self::assertTrue($service->currencyExists('USD'));
        self::assertTrue($service->currencyExists('UAH'));
    }

    public function testCurrencyExistsReturnsFalseForUnknownCurrency(): void
    {
        $service = $this->makeServiceStub();

        self::assertFalse($service->currencyExists('GBP'));
        self::assertFalse($service->currencyExists('CHF'));
        self::assertFalse($service->currencyExists(''));
    }

    public function testCurrencyExistsIsCaseSensitive(): void
    {
        $service = $this->makeServiceStub();

        // Keys in rates array are uppercase; lowercase should NOT match
        self::assertFalse($service->currencyExists('eur'));
        self::assertFalse($service->currencyExists('usd'));
    }

    // ---------------------------------------------------------------------------
    // convert() — returns values for ALL allowed currencies
    // ---------------------------------------------------------------------------

    public function testConvertReturnsEmptyArrayForUnknownSourceCurrency(): void
    {
        $service = $this->makeServiceStub();

        self::assertSame([], $service->convert(100.0, 'GBP'));
    }

    public function testConvertReturnsValuesForAllAllowedCurrencies(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        $result = $service->convert(1.0, 'EUR');

        self::assertArrayHasKey('EUR', $result);
        self::assertArrayHasKey('USD', $result);
        self::assertArrayHasKey('HUF', $result);
        self::assertArrayHasKey('UAH', $result);
        self::assertCount(\count(self::RATES), $result);
    }

    public function testConvertFromEurToUsdGivesCorrectRate(): void
    {
        // EUR rate = 1.0, USD rate = 1.1 → 1 EUR = 1.1 USD
        $service = $this->makeServiceStub(self::RATES);

        $result = $service->convert(1.0, 'EUR');

        self::assertEqualsWithDelta(1.1, $result['USD'], 0.0001);
    }

    public function testConvertFromUsdToEurGivesCorrectCrossRate(): void
    {
        // 1 USD: amount/rates[USD]*rates[EUR] = 1/1.1*1.0 ≈ 0.909
        $service = $this->makeServiceStub(self::RATES);

        $result = $service->convert(1.0, 'USD');

        self::assertEqualsWithDelta(1.0 / 1.1, $result['EUR'], 0.0001);
    }

    public function testConvertSameCurrencyReturnsSameAmount(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        $result = $service->convert(250.0, 'EUR');

        self::assertEqualsWithDelta(250.0, $result['EUR'], 0.0001);
    }

    public function testConvertZeroAmountReturnsZeroForAll(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        $result = $service->convert(0.0, 'EUR');

        foreach ($result as $value) {
            self::assertEqualsWithDelta(0.0, $value, 0.0001);
        }
    }

    public function testConvertWithHistoricalDatePassesDateThrough(): void
    {
        $yesterday = CarbonImmutable::yesterday();
        $historicalRates = ['EUR' => 1.0, 'USD' => 1.05, 'HUF' => 380.0, 'UAH' => 38.0];

        $service = $this->makeServiceStub($historicalRates);
        $service->method('getHistorical')->willReturn($historicalRates);

        $result = $service->convert(1.0, 'EUR', $yesterday);

        self::assertEqualsWithDelta(1.05, $result['USD'], 0.0001);
    }

    // ---------------------------------------------------------------------------
    // convertTo()
    // ---------------------------------------------------------------------------

    public function testConvertToThrowsForInvalidFromCurrency(): void
    {
        $service = $this->makeServiceStub();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fromCurrency/');

        $service->convertTo(100.0, 'GBP', 'EUR');
    }

    public function testConvertToThrowsForInvalidToCurrency(): void
    {
        $service = $this->makeServiceStub();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/toCurrency/');

        $service->convertTo(100.0, 'EUR', 'GBP');
    }

    public function testConvertToThrowsForBothInvalidCurrencies(): void
    {
        $service = $this->makeServiceStub();

        // fromCurrency is validated first
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fromCurrency/');

        $service->convertTo(100.0, 'XYZ', 'ABC');
    }

    public function testConvertToSameCurrencyReturnsOriginalAmount(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        // 100 EUR → EUR: 100 / 1.0 * 1.0 = 100
        self::assertEqualsWithDelta(100.0, $service->convertTo(100.0, 'EUR', 'EUR'), 0.0001);
    }

    public function testConvertToEurToUsd(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        // 100 EUR → USD: 100 / 1.0 * 1.1 = 110
        self::assertEqualsWithDelta(110.0, $service->convertTo(100.0, 'EUR', 'USD'), 0.0001);
    }

    public function testConvertToUsdToHuf(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        // 100 USD → HUF: 100 / 1.1 * 390 ≈ 35454.54
        $expected = 100.0 / 1.1 * 390.0;
        self::assertEqualsWithDelta($expected, $service->convertTo(100.0, 'USD', 'HUF'), 0.01);
    }

    public function testConvertToWithNullDateUsesLatestRates(): void
    {
        /** @var FixerService&MockObject $service */
        $service = $this->getMockBuilder(FixerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLatest', 'getHistorical'])
            ->getMock();

        $service->expects(self::atLeastOnce())->method('getLatest')->willReturn(self::RATES);
        $service->expects(self::never())->method('getHistorical');

        $service->convertTo(100.0, 'EUR', 'USD', null);
    }

    public function testConvertToWithHistoricalDateUsesHistoricalRates(): void
    {
        $yesterday = CarbonImmutable::yesterday();
        $historicalRates = ['EUR' => 1.0, 'USD' => 1.05, 'HUF' => 380.0, 'UAH' => 38.0];

        /** @var FixerService&MockObject $service */
        $service = $this->getMockBuilder(FixerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLatest', 'getHistorical'])
            ->getMock();

        // getLatest IS called (by currencyExists); getHistorical called for getRates
        $service->method('getLatest')->willReturn($historicalRates);
        $service->method('getHistorical')->willReturn($historicalRates);

        $result = $service->convertTo(100.0, 'EUR', 'USD', $yesterday);

        self::assertEqualsWithDelta(105.0, $result, 0.0001);
    }

    public function testConvertToZeroAmountReturnsZero(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        self::assertEqualsWithDelta(0.0, $service->convertTo(0.0, 'EUR', 'USD'), 0.0001);
    }

    public function testConvertToNegativeAmountPreservesSign(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        // -100 EUR → USD = -110
        self::assertEqualsWithDelta(-110.0, $service->convertTo(-100.0, 'EUR', 'USD'), 0.0001);
    }

    // ---------------------------------------------------------------------------
    // convertToBaseCurrency() — needs Security injection via reflection
    // ---------------------------------------------------------------------------

    public function testConvertToBaseCurrencyDelegatesToConvertToWithUserBaseCurrency(): void
    {
        /** @var \App\Entity\User&MockObject $user */
        $user = $this->createMock(\App\Entity\User::class);
        $user->method('getBaseCurrency')->willReturn('EUR');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        /** @var FixerService&MockObject $service */
        $service = $this->getMockBuilder(FixerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['convertTo', 'getLatest'])
            ->getMock();

        $securityProperty = new ReflectionProperty(FixerService::class, 'security');
        $securityProperty->setAccessible(true);
        $securityProperty->setValue($service, $security);

        $service->expects(self::once())
            ->method('convertTo')
            ->with(100.0, 'USD', 'EUR', null)
            ->willReturn(90.0);

        $result = $service->convertToBaseCurrency(100.0, 'USD', null);

        self::assertEqualsWithDelta(90.0, $result, 0.0001);
    }

    // ---------------------------------------------------------------------------
    // getLatest() — caching behaviour
    // ---------------------------------------------------------------------------

    public function testGetLatestStoresResultInCacheUnderTodayKey(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $apiRates = ['EUR' => 1.0, 'USD' => 1.1];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode(['rates' => $apiRates]));

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())
            ->method('get')
            ->with("fixer.$today", self::anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createStub(ItemInterface::class);

                return $callback($item);
            });

        $service = $this->makeServiceWithDependencies($httpClient, $cache);

        $result = $service->getLatest();

        // json_decode may return int 1 for float 1.0; use assertEquals not assertSame
        self::assertEquals($apiRates, $result);
    }

    public function testGetLatestUsesCachedValueOnSecondCall(): void
    {
        $cachedRates = ['EUR' => 1.0, 'USD' => 1.2];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn($cachedRates);

        $service = $this->makeServiceWithDependencies($httpClient, $cache);

        $result = $service->getLatest();

        self::assertSame($cachedRates, $result);
    }

    // ---------------------------------------------------------------------------
    // getHistorical() — date routing in cache key
    // ---------------------------------------------------------------------------

    public function testGetHistoricalStoresResultUnderDateKey(): void
    {
        $date = CarbonImmutable::parse('2024-06-15');
        $apiRates = ['EUR' => 1.0, 'USD' => 1.08];

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode(['rates' => $apiRates]));

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())
            ->method('get')
            ->with('fixer.2024-06-15', self::anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createStub(ItemInterface::class);

                return $callback($item);
            });

        $service = $this->makeServiceWithDependencies($httpClient, $cache);

        $result = $service->getHistorical($date);

        // json_decode may return int 1 for float 1.0; use assertEquals not assertSame
        self::assertEquals($apiRates, $result);
    }

    // ---------------------------------------------------------------------------
    // fetchRates() — HTTP error handling (via public wrappers that call it)
    // ---------------------------------------------------------------------------

    public function testFetchRatesThrowsRuntimeExceptionOnTransportError(): void
    {
        // Use a concrete exception that implements TransportExceptionInterface
        $transportException = new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException($transportException);

        $service = $this->makeServiceWithDependencies($httpClient, $this->makeCacheThatExecutesCallback());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to fetch rates from the Fixer API/');

        $service->getLatest();
    }

    public function testFetchRatesReturnsEmptyArrayWhenApiResponseMissingRatesKey(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode(['success' => false]));

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = $this->makeServiceWithDependencies($httpClient, $this->makeCacheThatExecutesCallback());

        self::assertSame([], $service->getLatest());
    }

    public function testFetchRatesThrowsJsonExceptionOnMalformedResponse(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn('not-valid-json{{{');

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $service = $this->makeServiceWithDependencies($httpClient, $this->makeCacheThatExecutesCallback());

        $this->expectException(JsonException::class);

        $service->getLatest();
    }

    public function testFetchRatesPassesCorrectQueryParameters(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode(['rates' => []]));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                '/latest',
                self::callback(static function (array $options) {
                    return isset($options['query']['access_key'])
                        && 'test-api-key' === $options['query']['access_key']
                        && self::BASE_CURRENCY === $options['query']['base']
                        && str_contains($options['query']['symbols'], 'EUR');
                }),
            )
            ->willReturn($response);

        $service = $this->makeServiceWithDependencies($httpClient, $this->makeCacheThatExecutesCallback());

        $service->getLatest();
    }

    public function testFetchRatesHistoricalEndpointContainsDateString(): void
    {
        $date = CarbonImmutable::parse('2023-12-25');

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn(json_encode(['rates' => []]));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('GET', '/2023-12-25', self::anything())
            ->willReturn($response);

        $service = $this->makeServiceWithDependencies($httpClient, $this->makeCacheThatExecutesCallback());

        $service->getHistorical($date);
    }

    // ---------------------------------------------------------------------------
    // Cross-rate precision corner cases
    // ---------------------------------------------------------------------------

    public function testConvertToHighValueAmountMaintainsReasonablePrecision(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        // 1,000,000 EUR → HUF at 390
        $result = $service->convertTo(1_000_000.0, 'EUR', 'HUF');

        self::assertEqualsWithDelta(390_000_000.0, $result, 1.0);
    }

    public function testConvertToVerySmallAmountDoesNotUnderflow(): void
    {
        $service = $this->makeServiceStub(self::RATES);

        // 0.000001 EUR → USD
        $result = $service->convertTo(0.000001, 'EUR', 'USD');

        self::assertGreaterThan(0.0, $result);
        self::assertEqualsWithDelta(0.0000011, $result, 0.00000001);
    }

    public function testConvertFromHighRateCurrencyToLowRateCurrency(): void
    {
        // UAH (40) → USD (1.1): 1000 / 40 * 1.1 = 27.5
        $service = $this->makeServiceStub(self::RATES);

        $result = $service->convertTo(1000.0, 'UAH', 'USD');

        self::assertEqualsWithDelta(27.5, $result, 0.0001);
    }
}
