<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Debt;
use App\Entity\ExpenseCategory;
use App\Entity\Transfer;
use App\Tests\BaseApiTestCase;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * API contract tests for Ledger endpoints.
 *
 * Endpoints covered:
 *   GET /api/v2/ledgers — unified list of transactions and transfers with filters
 *
 * Fixtures: BaseApiTestCase (shared accounts, categories, transactions)
 *
 * @group ledger
 */
class LedgerControllerTest extends BaseApiTestCase
{
    private const LEDGER_URL = '/api/v2/ledgers';

    /**
     * @covers \App\Controller\LedgerController::list
     *
     * @group smoke
     */
    public function testUnauthenticatedRequestIsRejected(): void
    {
        $this->client->request('GET', self::LEDGER_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\LedgerController::list
     *
     * @group smoke
     */
    public function testAuthenticatedUserCanAccessLedger(): void
    {
        $response = $this->client->request('GET', self::LEDGER_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('list', $content);
        self::assertArrayHasKey('count', $content);
        self::assertArrayHasKey('totalValue', $content);
        self::assertIsArray($content['list']);
        self::assertIsInt($content['count']);
        self::assertIsFloat($content['totalValue']);
    }

    /**
     * The ledger must NOT return transactions that are linked to a transfer (t.transfer IS NOT NULL).
     * It MUST return the Transfer entities themselves.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTransferTransactionsAreExcludedAndTransfersIncluded(): void
    {
        // Create a transfer through the API so its bookkeeping transactions are persisted
        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '50.0',
                'executedAt' => '2025-06-15T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Ledger test transfer',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-06-01',
            'before' => '2025-06-30',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        $list = $content['list'];

        // Every item in the list must NOT have a 'transfer' field linking it to a Transfer
        // (those bookkeeping transactions should be absent).
        foreach ($list as $item) {
            if (isset($item['type'])) {
                // It's a transaction — must not have a non-null transfer reference
                self::assertNull(
                    $item['transfer'] ?? null,
                    'Transaction linked to a transfer must not appear in the ledger list.',
                );
            }
        }

        // The Transfer we created must appear in the list
        $transferItems = array_filter($list, static fn (array $item): bool => isset($item['from'], $item['to']));
        self::assertNotEmpty($transferItems, 'Transfer must appear in the ledger list.');

        // Assert the Transfer is fully serialized — not just {id: N}
        $transfer = reset($transferItems);

        // from / to must contain full account sub-fields, not just id
        foreach (['from', 'to'] as $side) {
            self::assertArrayHasKey('id', $transfer[$side], "$side.id must be present");
            self::assertArrayHasKey('name', $transfer[$side], "$side.name must be present (JMS group missing if not)");
            self::assertArrayHasKey('currency', $transfer[$side], "$side.currency must be present");
        }

        // amount / rate / fee must be present and numeric (not serialized as opaque strings)
        self::assertArrayHasKey('amount', $transfer, 'amount must be present');
        self::assertArrayHasKey('rate', $transfer, 'rate must be present');
        self::assertArrayHasKey('fee', $transfer, 'fee must be present');
        self::assertIsNumeric($transfer['amount'], 'amount must be numeric, not a plain string');
        self::assertIsNumeric($transfer['rate'], 'rate must be numeric, not a plain string');
        self::assertIsNumeric($transfer['fee'], 'fee must be numeric, not a plain string');

        // transactions array must be present and each item must be a full transaction
        self::assertArrayHasKey('transactions', $transfer, 'transactions must be present');
        self::assertIsArray($transfer['transactions']);
        self::assertNotEmpty($transfer['transactions'], 'Transfer must have at least one linked transaction');
    }

    /**
     * type=expense returns only expense transactions, no transfers.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTypeExpenseFilterReturnsOnlyExpenses(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'type' => 'expense',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        foreach ($content['list'] as $item) {
            self::assertArrayHasKey('type', $item, 'Expense-filtered item must be a transaction.');
            self::assertEquals('expense', $item['type'], 'All items must be expenses.');
            self::assertArrayNotHasKey('from', $item, 'No transfer items should appear.');
        }
    }

    /**
     * type=income returns only income transactions, no transfers.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTypeIncomeFilterReturnsOnlyIncomes(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'type' => 'income',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        foreach ($content['list'] as $item) {
            self::assertArrayHasKey('type', $item);
            self::assertEquals('income', $item['type']);
            self::assertArrayNotHasKey('from', $item);
        }
    }

    /**
     * type=transfer returns only transfer items, no transactions.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTypeTransferFilterReturnsOnlyTransfers(): void
    {
        // Ensure at least one transfer exists in the test range
        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '10.0',
                'executedAt' => '2025-07-01T09:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Type=transfer test',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-07-01',
            'before' => '2025-07-31',
            'type' => 'transfer',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        foreach ($content['list'] as $item) {
            self::assertArrayHasKey('from', $item, 'Only Transfer items must appear when type=transfer.');
            self::assertArrayHasKey('to', $item);
            self::assertArrayNotHasKey('type', $item, 'Transaction items must not appear when type=transfer.');
        }

        self::assertNotEmpty($content['list'], 'At least the created transfer must appear.');
    }

    /**
     * Pagination: page + perPage work correctly and count reflects total regardless of page.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testPagination(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        // Full count
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
        ]));
        self::assertResponseIsSuccessful();
        $full = $response->toArray();
        $total = $full['count'];

        // Page 1 with perPage=1 — must return exactly 1 item
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 1,
            'page' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(1, $content['list']);
        self::assertEquals($total, $content['count'], 'count must reflect total, not current page size.');

        // Page 2
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 1,
            'page' => 2,
        ]));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $response->toArray()['list']);
    }

    /**
     * Items are returned sorted descending by executedAt.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testItemsAreSortedDescendingByExecutedAt(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'perPage' => 50,
        ]));
        self::assertResponseIsSuccessful();

        $items = $response->toArray()['list'];
        if (\count($items) < 2) {
            self::markTestSkipped('Not enough items to verify sort order.');
        }

        for ($i = 1; $i < \count($items); ++$i) {
            self::assertLessThanOrEqual(
                strtotime($items[$i - 1]['executedAt']),
                strtotime($items[$i]['executedAt']),
                'Items must be sorted descending by executedAt.',
            );
        }
    }

    /**
     * account[] filter returns only items linked to the specified account.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testAccountFilter(): void
    {
        $accountId = $this->accountCashEUR->getId();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'account[]' => [$accountId],
        ]));
        self::assertResponseIsSuccessful();

        $items = $response->toArray()['list'];

        foreach ($items as $item) {
            if (isset($item['type'])) {
                // Transaction: verify account id
                self::assertEquals(
                    $accountId,
                    $item['account']['id'],
                    'Transaction must belong to the filtered account.',
                );
            }
            // Transfers with matching from/to are allowed; we just verify no crash
        }
    }

    /**
     * category[] filter returns only transactions in the selected category.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testCategoryFilter(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $this->createExpense(
            amount: 12.34,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: Carbon::parse('2025-08-10T12:00:00Z'),
            note: 'ledger-category-filter-hit',
        );

        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-08-01',
            'before' => '2025-08-31',
            'type' => 'expense',
            'category[]' => [$groceries->getId()],
        ]));
        self::assertResponseIsSuccessful();

        $items = $response->toArray()['list'];
        self::assertNotEmpty($items);

        $notes = [];
        foreach ($items as $item) {
            self::assertSame('expense', $item['type']);
            self::assertSame($groceries->getId(), $item['category']['id']);
            $notes[] = $item['note'] ?? null;
        }

        self::assertContains('ledger-category-filter-hit', $notes);
    }

    /**
     * debt[] filter returns only transactions linked to the selected debt.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testDebtFilter(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $debt = (new Debt())
            ->setDebtor('Ledger Debt Filter')
            ->setBalance(0)
            ->setCurrency('EUR')
            ->setNote('ledger-debt-filter')
            ->setCreatedAt(Carbon::parse('2025-09-01T00:00:00Z'))
            ->setOwner($this->testUser);
        $this->entityManager()->persist($debt);
        $this->entityManager()->flush();

        $this->createExpense(
            amount: 15.0,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: Carbon::parse('2025-09-10T12:00:00Z'),
            note: 'ledger-debt-filter-hit',
            debt: $debt,
        );

        $this->createExpense(
            amount: 11.0,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: Carbon::parse('2025-09-11T12:00:00Z'),
            note: 'ledger-debt-filter-miss',
        );

        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-09-01',
            'before' => '2025-09-30',
            'type' => 'expense',
            'debt[]' => [$debt->getId()],
        ]));
        self::assertResponseIsSuccessful();

        $items = $response->toArray()['list'];
        self::assertNotEmpty($items);
        self::assertCount(1, $items);
        self::assertSame('ledger-debt-filter-hit', $items[0]['note']);
    }

    /**
     * note filter matches both transactions and transfers by substring.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testNoteFilterMatchesTransactionsAndTransfers(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $this->createExpense(
            amount: 9.99,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: Carbon::parse('2025-10-10T10:00:00Z'),
            note: 'needle tx record',
        );

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '20.0',
                'executedAt' => '2025-10-11T10:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'needle transfer record',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-10-01',
            'before' => '2025-10-31',
            'note' => 'needle',
        ]));
        self::assertResponseIsSuccessful();

        $items = $response->toArray()['list'];
        self::assertCount(2, $items);

        $notes = array_map(static fn (array $item): ?string => $item['note'] ?? null, $items);
        self::assertContains('needle tx record', $notes);
        self::assertContains('needle transfer record', $notes);
    }

    /**
     * isDraft=1 returns only draft transactions; isDraft=0 excludes drafts.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testIsDraftFilter(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $date = CarbonImmutable::parse('2025-11-15T12:00:00Z');

        $draft = $this->createExpense(
            amount: 5.0,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: $date,
            note: 'ledger-draft-hit',
        );
        $draft->setIsDraft(true);
        $this->entityManager()->flush();

        $this->createExpense(
            amount: 6.0,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: $date,
            note: 'ledger-draft-miss',
        );

        $this->entityManager()->clear();

        // Also create a transfer in the same period — must NOT appear when isDraft is set
        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '3.0',
                'executedAt' => '2025-11-15T12:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'ledger-draft-transfer',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        // isDraft=1 → only draft transactions, no transfers
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-11-01',
            'before' => '2025-11-30',
            'isDraft' => '1',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertArrayNotHasKey('from', $item, 'Transfers must not appear when isDraft is set.');
            self::assertTrue($item['isDraft'], 'isDraft=1 must return only draft transactions.');
        }
        $notes = array_column($items, 'note');
        self::assertContains('ledger-draft-hit', $notes);
        self::assertNotContains('ledger-draft-miss', $notes);

        // isDraft=0 → only non-draft transactions, no transfers
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-11-01',
            'before' => '2025-11-30',
            'isDraft' => '0',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        self::assertNotEmpty($items);
        foreach ($items as $item) {
            self::assertArrayNotHasKey('from', $item, 'Transfers must not appear when isDraft is set.');
            self::assertFalse($item['isDraft'], 'isDraft=0 must return only non-draft transactions.');
        }
        $notes = array_column($items, 'note');
        self::assertContains('ledger-draft-miss', $notes);
        self::assertNotContains('ledger-draft-hit', $notes);
    }

    /**
     * totalValue sums only transaction net values, not transfers.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTotalValueExcludesTransfers(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'type' => 'expense',
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // totalValue for expense-only filter must be <= 0 (expenses are negative)
        self::assertLessThanOrEqual(0.0, $content['totalValue']);
    }

    /**
     * withNestedCategories=1 expands a parent category to include its descendants.
     * withNestedCategories=0 (or absent) matches only the exact category IDs.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testWithNestedCategoriesFilter(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $foodCategory = $groceries->getParent();
        \assert(null !== $foodCategory, 'Groceries must have a parent (Food & Drinks) in fixtures.');

        $date = Carbon::parse('2026-01-15T12:00:00Z');

        // Transaction under child (Groceries) — must appear when parent is filtered with withNested=1
        $this->createExpense(
            amount: 7.50,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: $date,
            note: 'ledger-nested-cat-hit',
        );

        $this->entityManager()->clear();

        // withNested=1 + parent category ID → child transaction must appear
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-01-01',
            'before' => '2026-01-31',
            'type' => 'expense',
            'category[]' => [$foodCategory->getId()],
            'withNestedCategories' => '1',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');
        self::assertContains('ledger-nested-cat-hit', $notes, 'Child-category transaction must appear when withNestedCategories=1.');

        // withNested=0 (or absent) + parent category ID → child transaction must NOT appear
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-01-01',
            'before' => '2026-01-31',
            'type' => 'expense',
            'category[]' => [$foodCategory->getId()],
            'withNestedCategories' => '0',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');
        self::assertNotContains('ledger-nested-cat-hit', $notes, 'Child-category transaction must NOT appear when withNestedCategories=0.');
    }

    /**
     * currencies[] filter returns only transactions whose account currency matches.
     * Transfers must be excluded when currencies filter is active.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testCurrenciesFilter(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $date = Carbon::parse('2026-02-10T12:00:00Z');

        $this->createExpense(
            amount: 20.0,
            account: $this->accountCashEUR,
            category: $groceries,
            executedAt: $date,
            note: 'ledger-currencies-eur',
        );
        $this->createExpense(
            amount: 500.0,
            account: $this->accountCashUAH,
            category: $groceries,
            executedAt: $date,
            note: 'ledger-currencies-uah',
        );

        // Also add a transfer in the same period — it must NOT appear when currencies filter is active
        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '5.0',
                'executedAt' => '2026-02-10T12:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'ledger-currencies-transfer',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        // EUR only
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-02-01',
            'before' => '2026-02-28',
            'currencies[]' => ['EUR'],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];

        foreach ($items as $item) {
            // No transfer items (they have no currency field of their own)
            self::assertArrayNotHasKey('from', $item, 'Transfers must be excluded when currencies filter is active.');
            self::assertSame('EUR', $item['account']['currency'], 'Only EUR transactions must appear.');
        }

        $notes = array_column($items, 'note');
        self::assertContains('ledger-currencies-eur', $notes);
        self::assertNotContains('ledger-currencies-uah', $notes);

        // UAH only
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-02-01',
            'before' => '2026-02-28',
            'currencies[]' => ['UAH'],
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];

        $notes = array_column($items, 'note');
        self::assertContains('ledger-currencies-uah', $notes);
        self::assertNotContains('ledger-currencies-eur', $notes);
    }

    /**
     * amount[gte] / amount[lte] filter returns only transactions within the range.
     * Transfers must be excluded when amount filters are active.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testAmountRangeFilter(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $date = Carbon::parse('2026-03-10T12:00:00Z');

        $this->createExpense(amount: 10.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'amt-10');
        $this->createExpense(amount: 50.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'amt-50');
        $this->createExpense(amount: 200.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'amt-200');

        // gte=20, lte=100 → only amt-50
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'expense',
            'amount[gte]' => '20',
            'amount[lte]' => '100',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];

        $notes = array_column($items, 'note');
        self::assertContains('amt-50', $notes);
        self::assertNotContains('amt-10', $notes);
        self::assertNotContains('amt-200', $notes);

        // gte only
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'expense',
            'amount[gte]' => '100',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');
        self::assertContains('amt-200', $notes);
        self::assertNotContains('amt-10', $notes);
        self::assertNotContains('amt-50', $notes);

        // lte only
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'expense',
            'amount[lte]' => '15',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');
        self::assertContains('amt-10', $notes);
        self::assertNotContains('amt-50', $notes);
        self::assertNotContains('amt-200', $notes);
    }

    /**
     * Amount filter must also apply to transfers — transfers within the range appear,
     * those outside are excluded.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testAmountRangeFilterAppliesToTransfers(): void
    {
        $date = '2026-03-10T12:00:00Z';

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '25.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'transfer-small',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '150.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'transfer-large',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '500.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'transfer-huge',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        // type=transfer with gte=100, lte=200 → only transfer-large
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'transfer',
            'amount[gte]' => '100',
            'amount[lte]' => '200',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');

        self::assertContains('transfer-large', $notes);
        self::assertNotContains('transfer-small', $notes);
        self::assertNotContains('transfer-huge', $notes);
    }

    /**
     * When no type filter is specified, amount filter applies to both transactions and transfers.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testAmountRangeFilterWithoutTypeIncludesMatchingTransfers(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $date = Carbon::parse('2026-03-10T12:00:00Z');

        $this->createExpense(amount: 75.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'exp-75');

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '80.0',
                'executedAt' => '2026-03-10T12:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'xfer-80',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '5.0',
                'executedAt' => '2026-03-10T12:00:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'xfer-5',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        // gte=50 → exp-75 and xfer-80 should appear, xfer-5 should not
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'amount[gte]' => '50',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');

        self::assertContains('exp-75', $notes);
        self::assertContains('xfer-80', $notes);
        self::assertNotContains('xfer-5', $notes);
    }

    /**
     * Boundary: amount exactly at gte or lte boundary should be included.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testAmountRangeFilterBoundaryValues(): void
    {
        $date = '2026-03-10T12:00:00Z';

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'boundary-exact',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        // gte=100, lte=100 → must include exact match
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'transfer',
            'amount[gte]' => '100',
            'amount[lte]' => '100',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');

        self::assertContains('boundary-exact', $notes);
    }

    /**
     * Transfer amount filter with gte only — no upper bound.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTransferAmountFilterGteOnly(): void
    {
        $date = '2026-03-10T12:00:00Z';

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '10.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'gte-small',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '999.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'gte-large',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'transfer',
            'amount[gte]' => '500',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');

        self::assertContains('gte-large', $notes);
        self::assertNotContains('gte-small', $notes);
    }

    /**
     * Transfer amount filter with lte only — no lower bound.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTransferAmountFilterLteOnly(): void
    {
        $date = '2026-03-10T12:00:00Z';

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '10.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'lte-small',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '999.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'lte-large',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'transfer',
            'amount[lte]' => '50',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];
        $notes = array_column($items, 'note');

        self::assertContains('lte-small', $notes);
        self::assertNotContains('lte-large', $notes);
    }

    /**
     * When amount filter returns no matching transfers, the result should have zero transfers.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTransferAmountFilterNoMatchReturnsEmpty(): void
    {
        $date = '2026-03-10T12:00:00Z';

        $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '50.0',
                'executedAt' => $date,
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'no-match-xfer',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $this->entityManager()->clear();

        // gte=1000 → no transfers should match
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'type' => 'transfer',
            'amount[gte]' => '1000',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray()['list'];

        self::assertEmpty($items);
    }

    /**
     * amount[gte] > amount[lte] must result in an error response (not 2xx).
     * TODO: improve to 400 by mapping InvalidArgumentException to BadRequestHttpException.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testInvalidAmountRangeReturnsError(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2026-03-01',
            'before' => '2026-03-31',
            'amount[gte]' => '500',
            'amount[lte]' => '100',
        ]));

        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), 'amount[gte] > amount[lte] must not return 2xx.');
    }

    /**
     * Regression: when withNestedCategories=1 and the requested category IDs do not exist,
     * getCategoriesWithDescendantsByType() returns [] and $categoryIds becomes [].
     * The old code passed `null` (no filter) → all transactions returned.
     * The fix passes [0] as an impossible sentinel → zero results.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testWithNestedCategoriesNonExistentIdReturnsEmpty(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'category[]' => [999999],
            'withNestedCategories' => '1',
        ]));

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertSame(0, $content['count'], 'Non-existent category IDs must yield zero results, not all transactions.');
        self::assertSame([], $content['list']);
    }

    /**
     * Regression: totalValue must be computed via SQL (sumConverted), not by iterating the
     * in-memory $transactions array. When perPage=1, the page contains one item, but
     * totalValue must still reflect ALL matching transactions, not just the one on the page.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testTotalValueCoversAllPagesNotJustCurrentPage(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $date = Carbon::parse('2025-11-15T12:00:00Z');

        $this->createExpense(amount: 100.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'tv-page-test-a');
        $this->createExpense(amount: 200.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'tv-page-test-b');
        $this->entityManager()->clear();

        // Fetch only the first page (1 item)
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2025-11-01',
            'before' => '2025-11-30',
            'type' => 'expense',
            'note' => 'tv-page-test',
            'perPage' => '1',
            'page' => '1',
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // count must be 2 (both transactions), totalValue must reflect both (not just the page)
        self::assertSame(2, $content['count']);
        self::assertCount(1, $content['list'], 'Only 1 item on page 1.');
        // totalValue should be negative (expenses) and cover both transactions
        self::assertLessThan(-100.0, $content['totalValue'], 'totalValue must cover transactions on ALL pages, not only the current page.');
    }

    /**
     * Combined filters: account + category + date range work together.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testCombinedFiltersAccountAndCategoryAndDateRange(): void
    {
        $groceries = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceries instanceof ExpenseCategory);

        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'type' => 'expense',
            'account[]' => [$this->accountCashEUR->getId()],
            'category[]' => [$groceries->getId()],
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        foreach ($content['list'] as $item) {
            self::assertSame('expense', $item['type']);
            self::assertSame($this->accountCashEUR->getId(), $item['account']['id']);
            self::assertSame($groceries->getId(), $item['category']['id']);
        }
    }

    /**
     * Empty date range returns zero results.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testEmptyDateRangeReturnsZeroResults(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2099-01-01',
            'before' => '2099-12-31',
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertSame(0, $content['count']);
        self::assertEmpty($content['list']);
        self::assertEqualsWithDelta(0.0, $content['totalValue'], 0.001);
    }

    /**
     * Complement: a non-existent category without withNestedCategories must also return empty.
     * This goes through the plain $categoryIds ?: null path — valid IDs, zero match.
     *
     * @covers \App\Controller\LedgerController::list
     */
    public function testNonExistentCategoryIdWithoutExpansionReturnsEmpty(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::LEDGER_URL, [
            'after' => '2021-01-01',
            'before' => '2021-01-31',
            'category[]' => [999999],
        ]));

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertSame(0, $content['count'], 'Non-existent category ID must yield zero results.');
    }
}
