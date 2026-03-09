<?php

namespace App\Tests\Bank;

use App\Bank\DTO\DraftTransactionData;
use App\Bank\Provider\MonobankProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for MonobankProvider::parseWebhookPayload().
 * No HTTP or DB interaction — pure logic.
 *
 * @group bank
 */
class MonobankProviderParseWebhookPayloadTest extends TestCase
{
    private MonobankProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MonobankProvider(
            monobankClient: $this->createMock(HttpClientInterface::class),
            cache: $this->createMock(CacheInterface::class),
            monobankApiKey: 'test_key',
            baseCurrency: 'EUR',
            allowedCurrencies: ['EUR', 'USD', 'UAH'],
        );
    }

    public function testNonStatementItemTypeReturnsNull(): void
    {
        self::assertNull($this->provider->parseWebhookPayload(['type' => 'PingConfirmation']));
    }

    public function testMissingTypeReturnsNull(): void
    {
        self::assertNull($this->provider->parseWebhookPayload(['data' => []]));
    }

    public function testMissingAccountReturnsNull(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'statementItem' => ['amount' => -5000, 'time' => 1700000000],
            ],
        ];

        self::assertNull($this->provider->parseWebhookPayload($payload));
    }

    public function testZeroAmountReturnsNull(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_1',
                'statementItem' => ['amount' => 0, 'time' => 1700000000],
            ],
        ];

        self::assertNull($this->provider->parseWebhookPayload($payload));
    }

    public function testExpensePayloadReturnsNegativeAmount(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_1',
                'statementItem' => [
                    'time'         => 1700000000,
                    'amount'       => -5000,    // -50.00 UAH
                    'currencyCode' => 980,       // UAH
                    'description'  => 'Coffee',
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertSame('acc_1', $result->externalAccountId);
        self::assertEqualsWithDelta(-50.0, $result->amount, 0.001);
        self::assertSame('UAH', $result->currency);
        self::assertStringContainsString('Coffee', $result->note);
    }

    public function testIncomePayloadReturnsPositiveAmount(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_2',
                'statementItem' => [
                    'time'         => 1700000000,
                    'amount'       => 10000,    // +100.00 UAH
                    'currencyCode' => 980,
                    'description'  => 'Salary',
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertInstanceOf(DraftTransactionData::class, $result);
        self::assertEqualsWithDelta(100.0, $result->amount, 0.001);
    }

    public function testCurrencyCodeMappedToIsoString(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_3',
                'statementItem' => [
                    'time'         => 1700000000,
                    'amount'       => -2000,
                    'currencyCode' => 840,       // USD
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertSame('USD', $result->currency);
    }

    public function testUnknownCurrencyCodeFallsBackToUah(): void
    {
        // Currency code 999 is not in CURRENCY_MAP; the provider defaults to 'UAH'.
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_4',
                'statementItem' => [
                    'time'         => 1700000000,
                    'amount'       => -100,
                    'currencyCode' => 999,
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertSame('UAH', $result->currency);
    }

    public function testDescriptionAndCommentAreMergedAsNote(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_5',
                'statementItem' => [
                    'time'        => 1700000000,
                    'amount'      => -500,
                    'description' => 'ATM withdrawal',
                    'comment'     => 'near office',
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertStringContainsString('ATM withdrawal', $result->note);
        self::assertStringContainsString('near office', $result->note);
    }

    public function testEmptyDescriptionDefaultsToFallbackNote(): void
    {
        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_6',
                'statementItem' => [
                    'time'   => 1700000000,
                    'amount' => -100,
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertSame('Monobank transaction', $result->note);
    }

    public function testTimestampIsParsedIntoExecutedAt(): void
    {
        $ts = 1700000000;

        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_7',
                'statementItem' => [
                    'time'   => $ts,
                    'amount' => -100,
                ],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        self::assertSame($ts, $result->executedAt->getTimestamp());
    }

    public function testMissingTimeFallsBackToCurrentTime(): void
    {
        $before = time();

        $payload = [
            'type' => 'StatementItem',
            'data' => [
                'account'       => 'acc_8',
                'statementItem' => ['amount' => -100],
            ],
        ];

        $result = $this->provider->parseWebhookPayload($payload);

        $after = time();

        self::assertGreaterThanOrEqual($before, $result->executedAt->getTimestamp());
        self::assertLessThanOrEqual($after,  $result->executedAt->getTimestamp());
    }
}
