<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Transfer;
use App\Tests\BaseApiTestCase;

/**
 * API contract tests for Transfer CRUD endpoints.
 *
 * Endpoints covered:
 *   GET    /api/transfers          (API Platform GetCollection)
 *   POST   /api/transfers          (API Platform Post)
 *   PUT    /api/transfers/{id}     (API Platform Put)
 *   DELETE /api/transfers/{id}     (API Platform Delete)
 *
 * Fixtures: TransferFixtures (1 transfer: EUR Cash → UAH Card, amount=100, rate=26, fee=0)
 */
class TransferCrudTest extends BaseApiTestCase
{
    private const TRANSFER_URL = '/api/transfers';

    // ──────────────────────────────────────────────────────────────────────
    //  LIST — response shape
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_returnsCorrectShape(): void
    {
        $response = $this->client->request('GET', self::TRANSFER_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        self::assertIsArray($items);
        self::assertNotEmpty($items, 'Fixture transfer must be present.');

        $transfer = $items[0];

        // Top-level keys expected by frontend
        self::assertArrayHasKey('id', $transfer);
        self::assertArrayHasKey('from', $transfer);
        self::assertArrayHasKey('to', $transfer);
        self::assertArrayHasKey('amount', $transfer);
        self::assertArrayHasKey('rate', $transfer);
        self::assertArrayHasKey('fee', $transfer);
        self::assertArrayHasKey('executedAt', $transfer);
        self::assertArrayHasKey('transactions', $transfer);

        // from / to are nested account objects with id, name, currency
        self::assertArrayHasKey('id', $transfer['from']);
        self::assertArrayHasKey('name', $transfer['from']);
        self::assertArrayHasKey('currency', $transfer['from']);

        self::assertArrayHasKey('id', $transfer['to']);
        self::assertArrayHasKey('name', $transfer['to']);
        self::assertArrayHasKey('currency', $transfer['to']);

        // transactions is an array of nested transaction objects
        self::assertIsArray($transfer['transactions']);
    }

    public function testListTransfers_fixtureTransferValues(): void
    {
        $response = $this->client->request('GET', self::TRANSFER_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        $fixtureTransfer = null;
        foreach ($items as $item) {
            if ((float) $item['amount'] === 100.0 && (float) $item['rate'] === 26.0) {
                $fixtureTransfer = $item;
                break;
            }
        }
        self::assertNotNull($fixtureTransfer, 'Fixture transfer (amount=100, rate=26) must exist.');
        self::assertEquals(0.0, (float) $fixtureTransfer['fee']);
        self::assertEquals('EUR Cash', $fixtureTransfer['from']['name']);
        self::assertEquals('EUR', $fixtureTransfer['from']['currency']);
        self::assertEquals('UAH Card', $fixtureTransfer['to']['name']);
        self::assertEquals('UAH', $fixtureTransfer['to']['currency']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — same currency
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransfer_sameCurrency_returnsCreatedTransfer(): void
    {
        $response = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '50.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashEUR), // same account type, same currency
                'note' => 'Same currency transfer',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        self::assertEquals(50.0, (float) $content['amount']);
        self::assertEquals(1.0, (float) $content['rate']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — cross currency with rate
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransfer_crossCurrencyWithRate_returnsCorrectValues(): void
    {
        $response = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '200.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Cross currency',
                'rate' => '40',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(200.0, (float) $content['amount']);
        self::assertEquals(40.0, (float) $content['rate']);

        // Verify transactions created
        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        self::assertNotNull($transfer->getFromExpense());
        self::assertEquals(200.0, $transfer->getFromExpense()->getAmount());
        self::assertNotNull($transfer->getToIncome());
        self::assertEquals(8000.0, $transfer->getToIncome()->getAmount()); // 200 * 40
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — with fee and feeAccount
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransfer_withFee_createsFeeTransaction(): void
    {
        $response = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'With fee',
                'rate' => '1',
                'fee' => '5.0',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(5.0, (float) $content['fee']);

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        self::assertNotNull($transfer->getFeeExpense(), 'Fee expense transaction must be created.');
        self::assertEquals(5.0, $transfer->getFeeExpense()->getAmount());
        self::assertEquals('Transfer Fee', $transfer->getFeeExpense()->getCategory()->getName());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE
    // ──────────────────────────────────────────────────────────────────────

    public function testUpdateTransfer_changesAmountAndNote(): void
    {
        // Create first
        $createResponse = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Original note',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $transferId = $createResponse->toArray()['id'];

        // Update
        $this->client->request('PUT', self::TRANSFER_URL . '/' . $transferId, [
            'json' => [
                'amount' => '75.0',
                'executedAt' => '2024-06-02T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Updated note',
                'rate' => '2',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        self::assertNotNull($transfer);
        self::assertEquals(75.0, $transfer->getAmount());
        self::assertEquals(2.0, $transfer->getRate());
        self::assertEquals('Updated note', $transfer->getNote());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DELETE
    // ──────────────────────────────────────────────────────────────────────

    public function testDeleteTransfer_returns204(): void
    {
        $createResponse = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '50.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $transferId = $createResponse->toArray()['id'];

        $this->client->request('DELETE', self::TRANSFER_URL . '/' . $transferId);
        self::assertResponseStatusCodeSame(204);

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        self::assertNull($transfer, 'Transfer must be deleted.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILTERS — accounts[]
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_accountsFilter_filtersByFromOrTo(): void
    {
        // Create a transfer from EUR → UAH
        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '10.0',
                'executedAt' => '2024-07-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Filter test',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Filter by EUR account — should include transfers where EUR is from OR to
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'accounts' => [$this->accountCashEUR->getId()],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertNotEmpty($items, 'Filter by EUR account must return results.');

        // Every returned transfer must have EUR as from or to
        foreach ($items as $item) {
            $eurId = $this->accountCashEUR->getId();
            $isFromOrTo = ($item['from']['id'] === $eurId) || ($item['to']['id'] === $eurId);
            self::assertTrue($isFromOrTo, 'Transfer must involve the filtered account.');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILTERS — date range
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_executedAtFilter(): void
    {
        // Fixture transfer is on 2021-06-15
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'executedAt' => [
                'after' => '2021-06-01',
                'before' => '2021-06-30',
            ],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertNotEmpty($items, 'Date filter must include fixture transfer.');

        // Outside range
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'executedAt' => [
                'after' => '2025-01-01',
                'before' => '2025-01-31',
            ],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertEmpty($items, 'Date filter outside range must return empty.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILTERS — amount range
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_amountRangeFilter(): void
    {
        // Fixture transfer amount = 100
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'amount' => ['gte' => 99, 'lte' => 101],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertNotEmpty($items, 'Amount range 99-101 must include fixture transfer.');

        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'amount' => ['gte' => 500],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();

        // All returned transfers must have amount >= 500
        foreach ($items as $item) {
            self::assertGreaterThanOrEqual(500, (float) $item['amount']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILTERS — note search
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_noteFilter(): void
    {
        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '10.0',
                'executedAt' => '2024-07-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Unique note for search test xyz123',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'note' => 'xyz123',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertNotEmpty($items, 'Note search must find the transfer.');
        self::assertStringContainsString('xyz123', $items[0]['note']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILTERS — pagination
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_pagination(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'itemsPerPage' => 1,
            'page' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertLessThanOrEqual(1, count($items), 'Pagination must limit results.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfers_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::TRANSFER_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASES — nested transactions in response
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfer_withFee_includesNestedTransactions(): void
    {
        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Nested transactions check',
                'rate' => '26',
                'fee' => '2.0',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();

        // GET collection uses transfer:collection:read which includes transactions
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'note' => 'Nested transactions check',
        ]));
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        self::assertNotEmpty($items);
        $transfer = $items[0];

        self::assertArrayHasKey('transactions', $transfer);
        self::assertIsArray($transfer['transactions']);
        // 2 base transactions (expense + income) + 1 fee = 3
        self::assertCount(3, $transfer['transactions'], 'Transfer with fee must have 3 transactions.');

        foreach ($transfer['transactions'] as $transaction) {
            self::assertArrayHasKey('id', $transaction);
            self::assertArrayHasKey('amount', $transaction);
            self::assertArrayHasKey('type', $transaction);
            self::assertArrayHasKey('executedAt', $transaction);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASE — fee creates additional expense entry
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransfer_feeUpdatesAccountBalance(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();

        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Fee balance check',
                'rate' => '1',
                'fee' => '10.0',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();

        // EUR balance should decrease by amount + fee = 110
        $this->em->refresh($this->accountCashEUR);
        $eurBalanceAfter = (float) $this->accountCashEUR->getBalance();
        self::assertEquals($eurBalanceBefore - 110.0, $eurBalanceAfter, 'EUR balance must decrease by amount + fee.');
    }
}
