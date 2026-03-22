<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CashAccount;
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
 * Fixtures: TransferFixtures (2 transfers: EUR Cash → UAH Card without fees, EUR Cash → UAH Card with single fee)
 */
class TransferCrudTest extends BaseApiTestCase
{
    private const TRANSFER_URL = '/api/transfers';

    // ──────────────────────────────────────────────────────────────────────
    //  LIST — response shape
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfersReturnsCorrectShape(): void
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

    public function testListTransfersFixtureTransferValues(): void
    {
        $response = $this->client->request('GET', self::TRANSFER_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        $fixtureTransfer = null;
        foreach ($items as $item) {
            if (100.0 === (float) $item['amount'] && 26.0 === (float) $item['rate']) {
                $fixtureTransfer = $item;
                break;
            }
        }
        self::assertNotNull($fixtureTransfer, 'Fixture transfer (amount=100, rate=26) must exist.');
        self::assertEquals('EUR Cash', $fixtureTransfer['from']['name']);
        self::assertEquals('EUR', $fixtureTransfer['from']['currency']);
        self::assertEquals('UAH Card', $fixtureTransfer['to']['name']);
        self::assertEquals('UAH', $fixtureTransfer['to']['currency']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — same currency
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransferSameCurrencyReturnsCreatedTransfer(): void
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

    public function testCreateTransferCrossCurrencyWithRateReturnsCorrectValues(): void
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
        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        self::assertNotNull($transfer->getFromExpense());
        self::assertEquals(200.0, $transfer->getFromExpense()->getAmount());
        self::assertNotNull($transfer->getToIncome());
        self::assertEquals(8000.0, $transfer->getToIncome()->getAmount()); // 200 * 40
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — with fees
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransferWithFeeCreatesFeeTransaction(): void
    {
        $response = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'With fee',
                'rate' => '1',
                'fees' => [
                    ['amount' => 5.0, 'account' => $this->accountCashEUR->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($response->toArray()['id']);
        self::assertNotNull($transfer);

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(1, $feeExpenses, 'Fee expense transaction must be created.');
        self::assertEquals(5.0, $feeExpenses[0]->getAmount());
        self::assertEquals('Transfer Fee', $feeExpenses[0]->getCategory()->getName());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — with multiple fees on different accounts
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransferWithMultipleFees(): void
    {
        $response = $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Multi fee',
                'rate' => '1',
                'fees' => [
                    ['amount' => 3.0, 'account' => $this->accountCashEUR->getId()],
                    ['amount' => 50.0, 'account' => $this->accountCashUAH->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($response->toArray()['id']);
        self::assertNotNull($transfer);

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(2, $feeExpenses, 'Two fee expense transactions must be created.');
        // 2 base + 2 fees = 4 transactions total
        self::assertCount(4, $transfer->getTransactions());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE
    // ──────────────────────────────────────────────────────────────────────

    public function testUpdateTransferChangesAmountAndNote(): void
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

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        self::assertNotNull($transfer);
        self::assertEquals(75.0, $transfer->getAmount());
        self::assertEquals(2.0, $transfer->getRate());
        self::assertEquals('Updated note', $transfer->getNote());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DELETE
    // ──────────────────────────────────────────────────────────────────────

    public function testDeleteTransferReturns204(): void
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

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        self::assertNull($transfer, 'Transfer must be deleted.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FILTERS — accounts[]
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfersAccountsFilterFiltersByFromOrTo(): void
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

    public function testListTransfersExecutedAtFilter(): void
    {
        // Fixture transfers are on 2021-06-15 and 2021-07-10
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'executedAt' => [
                'after' => '2021-06-01',
                'before' => '2021-07-31',
            ],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertNotEmpty($items, 'Date filter must include fixture transfers.');

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

    public function testListTransfersAmountRangeFilter(): void
    {
        // Fixture transfers: amount=100 and amount=200
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

    public function testListTransfersNoteFilter(): void
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

    public function testListTransfersPagination(): void
    {
        // Fixtures already provide 2 transfers; fetch all to confirm > 1 exist
        $allResponse = $this->client->request('GET', self::TRANSFER_URL);
        self::assertResponseIsSuccessful();
        $allItems = $allResponse->toArray();
        self::assertGreaterThan(1, \count($allItems), 'Must have more than 1 transfer to test pagination.');

        // Request page size = 1
        $response = $this->client->request('GET', $this->buildURL(self::TRANSFER_URL, [
            'perPage' => 1,
            'page' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();
        self::assertCount(1, $items, 'Pagination must limit results to 1.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransfersWithoutAuthReturns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::TRANSFER_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASES — nested transactions in response
    // ──────────────────────────────────────────────────────────────────────

    public function testListTransferWithFeeIncludesNestedTransactions(): void
    {
        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Nested transactions check',
                'rate' => '26',
                'fees' => [
                    ['amount' => 2.0, 'account' => $this->accountCashEUR->getId()],
                ],
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
    //  VALIDATION — amount and rate must be positive
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransferWithZeroAmountReturns422(): void
    {
        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '0',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateTransferWithNegativeRateReturns422(): void
    {
        $this->client->request('POST', self::TRANSFER_URL, [
            'json' => [
                'amount' => '100',
                'executedAt' => '2024-06-01T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '-1',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASE — fee creates additional expense entry
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateTransferFeeUpdatesAccountBalance(): void
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
                'fees' => [
                    ['amount' => 10.0, 'account' => $this->accountCashEUR->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // EUR balance should decrease by amount + fee = 110
        $this->entityManager()->refresh($this->accountCashEUR);
        $eurBalanceAfter = (float) $this->accountCashEUR->getBalance();
        self::assertEquals($eurBalanceBefore - 110.0, $eurBalanceAfter, 'EUR balance must decrease by amount + fee.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  ISOLATION — cross-user data must not be visible or modifiable
    // ──────────────────────────────────────────────────────────────────────

    private function createOtherUserTransfer(): array
    {
        $otherUser = $this->createOtherUser('transfer_iso');

        $fromAccount = new CashAccount();
        $fromAccount->setName('Other From EUR')->setCurrency('EUR')->setBalance(500.0)->setOwner($otherUser);
        $this->entityManager()->persist($fromAccount);

        $toAccount = new CashAccount();
        $toAccount->setName('Other To EUR')->setCurrency('EUR')->setBalance(0.0)->setOwner($otherUser);
        $this->entityManager()->persist($toAccount);

        $transfer = new Transfer();
        $transfer
            ->setFrom($fromAccount)
            ->setTo($toAccount)
            ->setAmount('200')
            ->setRate('1')
            ->setExecutedAt(new \DateTimeImmutable('2024-06-01T10:00:00Z'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($transfer);
        $this->entityManager()->flush();

        return [$otherUser, $transfer];
    }

    public function testListTransfers_withOtherUserData_returnsOnlyOwnData(): void
    {
        [, $otherTransfer] = $this->createOtherUserTransfer();

        $response = $this->client->request('GET', self::TRANSFER_URL);
        self::assertResponseIsSuccessful();

        $identifiers = array_column($response->toArray(), 'id');
        self::assertNotContains(
            $otherTransfer->getId(),
            $identifiers,
            'Other user\'s transfer must not appear in the authenticated user\'s list.',
        );
    }

    public function testGetTransfer_ownedByOtherUser_returns403(): void
    {
        [, $otherTransfer] = $this->createOtherUserTransfer();

        $this->client->request('GET', self::TRANSFER_URL . '/' . $otherTransfer->getId());
        // security: 'object.getOwner() == user' on the Get operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }

    public function testUpdateTransfer_ownedByOtherUser_returns403(): void
    {
        [, $otherTransfer] = $this->createOtherUserTransfer();
        $otherTransferId = $otherTransfer->getId();

        $this->client->request('PUT', self::TRANSFER_URL . '/' . $otherTransferId, [
            'json' => [
                'amount' => '1.00',
                'rate' => '1',
                'executedAt' => '2024-06-01T10:00:00+00:00',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
            ],
        ]);
        // security: 'object.getOwner() == user' on the Put operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteTransfer_ownedByOtherUser_returns403(): void
    {
        [, $otherTransfer] = $this->createOtherUserTransfer();

        $this->client->request('DELETE', self::TRANSFER_URL . '/' . $otherTransfer->getId());
        // security: 'object.getOwner() == user' on the Delete operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }
}
