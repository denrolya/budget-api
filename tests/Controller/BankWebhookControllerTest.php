<?php

namespace App\Tests\Controller;

use App\Bank\BankWebhookService;
use App\Entity\Expense;
use App\Tests\BaseApiTestCase;

/**
 * Tests for BankWebhookController — the public endpoint that banks POST events to.
 * No authentication is required (access_control opens /api/webhooks to PUBLIC_ACCESS).
 *
 * BankWebhookService is mocked throughout so these tests cover routing, request
 * parsing, and HTTP response codes without making real DB writes.
 *
 * @group bank
 */
class BankWebhookControllerTest extends BaseApiTestCase
{
    private const WEBHOOK_BASE = '/api/webhooks';

    // -------------------------------------------------------------------------
    // Provider slug routing
    // -------------------------------------------------------------------------

    public function testUnknownProviderReturns404(): void
    {
        $this->client->request('POST', self::WEBHOOK_BASE . '/notabank', [
            'json' => ['some' => 'data'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // -------------------------------------------------------------------------
    // Payload validation
    // -------------------------------------------------------------------------

    public function testInvalidJsonBodyReturns400(): void
    {
        $this->client->request('POST', self::WEBHOOK_BASE . '/monobank', [
            'body'    => 'not valid json {{{',
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    // -------------------------------------------------------------------------
    // Non-transaction events (ping, confirmation, etc.)
    // -------------------------------------------------------------------------

    /**
     * Monobank sends a ping/confirmation event when the webhook is first registered.
     * BankWebhookService returns null (not a transaction) → 200 OK with empty body.
     */
    public function testMonobankPingReturns200(): void
    {
        $mock = $this->createMock(BankWebhookService::class);
        $mock->method('handle')->willReturn(null);
        $this->client->getContainer()->set(BankWebhookService::class, $mock);

        $response = $this->client->request('POST', self::WEBHOOK_BASE . '/monobank', [
            'json' => ['type' => 'PingConfirmation'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);

        // Body must be an array (even if empty), not an error object.
        $content = $response->toArray();
        self::assertIsArray($content);
    }

    // -------------------------------------------------------------------------
    // Transaction events
    // -------------------------------------------------------------------------

    /**
     * When BankWebhookService creates a draft transaction the action returns 201
     * with {"id": <transaction-id>}.
     */
    public function testStatementItemCreatesTransactionAndReturns201(): void
    {
        // Use any existing Expense from fixtures as the "created" transaction.
        $expense = $this->em->getRepository(Expense::class)->findOneBy([]);
        self::assertNotNull($expense, 'TransactionFixtures must provide at least one Expense');

        $mock = $this->createMock(BankWebhookService::class);
        $mock->method('handle')->willReturn($expense);
        $this->client->getContainer()->set(BankWebhookService::class, $mock);

        $response = $this->client->request('POST', self::WEBHOOK_BASE . '/monobank', [
            'json' => [
                'type' => 'StatementItem',
                'data' => [
                    'account' => 'test_external_id',
                    'statementItem' => [
                        'time'         => 1740000000,
                        'amount'       => -5000,
                        'currencyCode' => 980,
                        'description'  => 'Coffee',
                    ],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        self::assertSame($expense->getId(), $content['id']);
    }

    /**
     * Duplicate or unknown-account events: service returns null → 200.
     */
    public function testUnknownAccountOrDuplicateReturns200(): void
    {
        $mock = $this->createMock(BankWebhookService::class);
        $mock->method('handle')->willReturn(null);
        $this->client->getContainer()->set(BankWebhookService::class, $mock);

        $response = $this->client->request('POST', self::WEBHOOK_BASE . '/monobank', [
            'json' => [
                'type' => 'StatementItem',
                'data' => ['account' => 'no_such_account', 'statementItem' => []],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    // -------------------------------------------------------------------------
    // Service exceptions
    // -------------------------------------------------------------------------

    public function testUnhandledServiceExceptionReturns500(): void
    {
        $mock = $this->createMock(BankWebhookService::class);
        $mock->method('handle')->willThrowException(new \RuntimeException('DB unavailable'));
        $this->client->getContainer()->set(BankWebhookService::class, $mock);

        $this->client->request('POST', self::WEBHOOK_BASE . '/monobank', [
            'json' => ['type' => 'StatementItem', 'data' => []],
        ]);

        self::assertResponseStatusCodeSame(500);
    }
}
