<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Bank\Provider\MonobankProvider;
use App\Bank\Provider\WiseProvider;
use App\Tests\BaseApiTestCase;
use Psr\Cache\InvalidArgumentException;

/**
 * API contract tests for ExchangeRates endpoints.
 *
 * Endpoints covered:
 *   GET /api/v2/exchange-rates/snapshots
 *   GET /api/v2/exchange-rates (alias: /fixer)
 *   GET /api/v2/exchange-rates/monobank
 *   GET /api/v2/exchange-rates/wise
 *
 * Note: Monobank and Wise endpoints call external APIs.
 * The fixer endpoint uses ExchangeRateSnapshotResolver which falls back to FixerService (mocked).
 */
class ExchangeRatesTest extends BaseApiTestCase
{
    private const SNAPSHOTS_URL = '/api/v2/exchange-rates/snapshots';
    private const FIXER_URL = '/api/v2/exchange-rates/fixer';
    private const BASE_URL = '/api/v2/exchange-rates';
    private const MONOBANK_URL = '/api/v2/exchange-rates/monobank';
    private const WISE_URL = '/api/v2/exchange-rates/wise';

    // ──────────────────────────────────────────────────────────────────────
    //  snapshots — response shape
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\ExchangeRatesController::snapshots
     */
    public function testSnapshots_returnsCorrectShape(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::SNAPSHOTS_URL, [
            'after' => '2021-01-01',
            'before' => '2021-12-31',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('after', $content);
        self::assertArrayHasKey('before', $content);
        self::assertArrayHasKey('snapshots', $content);
        self::assertIsArray($content['snapshots']);
        self::assertEquals('2021-01-01', $content['after']);
        self::assertEquals('2021-12-31', $content['before']);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::snapshots
     */
    public function testSnapshots_swappedDates_autoCorrects(): void
    {
        // When before < after, the controller swaps them
        $response = $this->client->request('GET', $this->buildURL(self::SNAPSHOTS_URL, [
            'after' => '2021-12-31',
            'before' => '2021-01-01',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('2021-01-01', $content['after']);
        self::assertEquals('2021-12-31', $content['before']);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::snapshots
     */
    public function testSnapshots_singleDate_beforeDefaultsToAfter(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::SNAPSHOTS_URL, [
            'after' => '2021-06-15',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('2021-06-15', $content['after']);
        self::assertEquals('2021-06-15', $content['before']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  fixer — response shape (uses mocked FixerService via BaseApiTestCase)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\ExchangeRatesController::fixerRates
     */
    public function testFixer_returnsRatesShape(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::FIXER_URL, [
            'date' => '2021-01-15',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('rates', $content);
        self::assertIsArray($content['rates']);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::fixerRates
     */
    public function testFixerBaseUrl_alsoWorks(): void
    {
        // GET /api/v2/exchange-rates is an alias for /fixer
        $response = $this->client->request('GET', $this->buildURL(self::BASE_URL, [
            'date' => '2021-01-15',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('rates', $content);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\ExchangeRatesController::snapshots
     */
    public function testSnapshots_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', $this->buildURL(self::SNAPSHOTS_URL, [
            'after' => '2021-01-01',
        ]));
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::fixerRates
     */
    public function testFixer_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::FIXER_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  monobank — response shape (mocked provider)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\ExchangeRatesController::monobankRates
     */
    public function testMonobankRates_returnsCorrectShape(): void
    {
        $mockProvider = $this->createMock(MonobankProvider::class);
        $mockProvider->method('getLatest')->willReturn(['USD' => 1.0, 'EUR' => 0.85, 'UAH' => 37.5]);
        $this->client->getContainer()->set(MonobankProvider::class, $mockProvider);

        $response = $this->client->request('GET', self::MONOBANK_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('rates', $content);
        self::assertIsArray($content['rates']);
        self::assertArrayHasKey('USD', $content['rates']);
        self::assertArrayHasKey('EUR', $content['rates']);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::monobankRates
     */
    public function testMonobankRates_providerError_returns500(): void
    {
        $mockProvider = $this->createMock(MonobankProvider::class);
        $mockProvider->method('getLatest')->willThrowException(
            new class ('Cache error') extends \RuntimeException implements InvalidArgumentException {}
        );
        $this->client->getContainer()->set(MonobankProvider::class, $mockProvider);

        $this->client->request('GET', self::MONOBANK_URL);
        self::assertResponseStatusCodeSame(500);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::monobankRates
     */
    public function testMonobankRates_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::MONOBANK_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  wise — response shape (mocked provider)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\ExchangeRatesController::wiseRates
     */
    public function testWiseRates_returnsCorrectShape(): void
    {
        $mockProvider = $this->createMock(WiseProvider::class);
        $mockProvider->method('getRates')->willReturn(['USD' => 1.0, 'EUR' => 0.85, 'GBP' => 0.73]);
        $this->client->getContainer()->set(WiseProvider::class, $mockProvider);

        $response = $this->client->request('GET', self::WISE_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('rates', $content);
        self::assertIsArray($content['rates']);
        self::assertArrayHasKey('USD', $content['rates']);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::wiseRates
     */
    public function testWiseRates_withDateParam_returnsRates(): void
    {
        $mockProvider = $this->createMock(WiseProvider::class);
        $mockProvider->method('getRates')->willReturn(['USD' => 1.0, 'EUR' => 0.88]);
        $this->client->getContainer()->set(WiseProvider::class, $mockProvider);

        $response = $this->client->request('GET', $this->buildURL(self::WISE_URL, [
            'date' => '2021-06-15',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('rates', $content);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::wiseRates
     */
    public function testWiseRates_providerError_returns500(): void
    {
        $mockProvider = $this->createMock(WiseProvider::class);
        $mockProvider->method('getRates')->willThrowException(
            new class ('Cache error') extends \RuntimeException implements InvalidArgumentException {}
        );
        $this->client->getContainer()->set(WiseProvider::class, $mockProvider);

        $this->client->request('GET', self::WISE_URL);
        self::assertResponseStatusCodeSame(500);
    }

    /**
     * @covers \App\Controller\ExchangeRatesController::wiseRates
     */
    public function testWiseRates_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::WISE_URL);
        self::assertResponseStatusCodeSame(401);
    }
}
