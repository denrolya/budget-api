<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\BaseApiTestCase;

/**
 * API contract tests for Account statistics endpoints.
 *
 * Endpoints covered:
 *   GET /api/v2/accounts/{id}/daily-stats
 *   GET /api/v2/accounts/{id}/balance-history
 */
class AccountStatsTest extends BaseApiTestCase
{
    // ──────────────────────────────────────────────────────────────────────
    //  daily-stats — response shape
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\AccountController::dailyStats
     */
    public function testDailyStatsReturnsCorrectShape(): void
    {
        $accountId = $this->accountCashEUR->getId();

        $response = $this->client->request('GET', $this->buildURL(
            "/api/v2/accounts/{$accountId}/daily-stats",
            ['after' => '2021-01-01', 'before' => '2021-01-31'],
        ));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
        self::assertNotEmpty($content['data']);

        $firstDay = $content['data'][0];
        self::assertArrayHasKey('day', $firstDay);
        self::assertArrayHasKey('count', $firstDay);
        self::assertArrayHasKey('convertedValues', $firstDay);
    }

    /**
     * @covers \App\Controller\AccountController::dailyStats
     */
    public function testDailyStatsEmptyRangeReturnsEmptyData(): void
    {
        $accountId = $this->accountCashEUR->getId();

        $response = $this->client->request('GET', $this->buildURL(
            "/api/v2/accounts/{$accountId}/daily-stats",
            ['after' => '2025-06-01', 'before' => '2025-06-30'],
        ));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertEmpty($content['data']);
    }

    /**
     * @covers \App\Controller\AccountController::dailyStats
     */
    public function testDailyStatsUahAccountReturnsData(): void
    {
        $accountId = $this->accountCashUAH->getId();

        $response = $this->client->request('GET', $this->buildURL(
            "/api/v2/accounts/{$accountId}/daily-stats",
            ['after' => '2021-01-01', 'before' => '2021-01-31'],
        ));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        // UAH Card has 5 expenses in Jan 2021
        self::assertNotEmpty($content['data']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  balance-history — response shape
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\AccountController::balanceHistory
     */
    public function testBalanceHistoryReturnsCorrectShape(): void
    {
        $accountId = $this->accountCashEUR->getId();

        $response = $this->client->request('GET', $this->buildURL(
            "/api/v2/accounts/{$accountId}/balance-history",
            ['after' => '2021-01-01', 'before' => '2021-06-30', 'interval' => 'P1M'],
        ));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('currency', $content);
        self::assertArrayHasKey('data', $content);
        self::assertEquals('EUR', $content['currency']);
        self::assertIsArray($content['data']);
        self::assertNotEmpty($content['data']);

        $firstPoint = $content['data'][0];
        self::assertArrayHasKey('timestamp', $firstPoint);
        self::assertArrayHasKey('balance', $firstPoint);
    }

    /**
     * @covers \App\Controller\AccountController::balanceHistory
     */
    public function testBalanceHistoryWeeklyInterval(): void
    {
        $accountId = $this->accountCashEUR->getId();

        $response = $this->client->request('GET', $this->buildURL(
            "/api/v2/accounts/{$accountId}/balance-history",
            ['after' => '2021-01-01', 'before' => '2021-01-31', 'interval' => 'P1W'],
        ));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertNotEmpty($content['data']);
        // Weekly intervals over 1 month should produce ~4-5 data points
        self::assertGreaterThanOrEqual(4, \count($content['data']));
    }

    /**
     * @covers \App\Controller\AccountController::balanceHistory
     */
    public function testBalanceHistoryEmptyRangeReturnsEmptyData(): void
    {
        $accountId = $this->accountCashEUR->getId();

        $response = $this->client->request('GET', $this->buildURL(
            "/api/v2/accounts/{$accountId}/balance-history",
            ['after' => '2025-06-01', 'before' => '2025-06-30', 'interval' => 'P1M'],
        ));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\AccountController::dailyStats
     */
    public function testDailyStatsWithoutAuthReturns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', '/api/v2/accounts/1/daily-stats');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\AccountController::balanceHistory
     */
    public function testBalanceHistoryWithoutAuthReturns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', '/api/v2/accounts/1/balance-history');
        self::assertResponseStatusCodeSame(401);
    }
}
