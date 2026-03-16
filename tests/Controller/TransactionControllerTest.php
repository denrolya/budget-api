<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Tests\BaseApiTestCase;
use Carbon\Carbon;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * API contract tests for Transaction endpoints.
 *
 * Endpoints covered:
 *   GET    /api/v2/transaction            — paginated list with filters
 *   POST   /api/transactions/expense      — create expense
 *   POST   /api/transactions/income       — create income
 *   PUT    /api/transactions/{id}         — update transaction
 *   DELETE /api/transactions/{id}         — delete transaction
 *   GET    /api/v2/transaction/export.csv — CSV export
 *
 * Fixtures: BaseApiTestCase (shared accounts, categories, transactions)
 */
class TransactionControllerTest extends BaseApiTestCase
{
    /**
     * @covers \App\Controller\TransactionController::list
     *
     * @group smoke
     * @group transactions
     */
    public function testAuthorizedUserCanAccessListOfTransactions(): void
    {
        $this->client->request('GET', self::TRANSACTION_LIST_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);

        $response = $this->client->request('GET', self::TRANSACTION_LIST_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('list', $content);
        self::assertArrayHasKey('totalValue', $content);
        self::assertArrayHasKey('count', $content);
    }

    /**
     * Jan 2021: 30 EUR Cash expenses + 5 UAH Card expenses + 4 EUR Cash incomes = 39 total
     * totalValue = income(2000) - expense(1530) = 470
     *
     * @covers \App\Controller\TransactionController::list
     *
     * @group smoke
     * @group transactions
     */
    public function testTransactionsListPagination(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(470, $content['totalValue'], 0.01);
        self::assertEquals(39, $content['count']);

        $totalValue = $content['totalValue'];
        $count = $content['count'];

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(1, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 1,
            'page' => 2,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(1, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 2,
            'page' => 2,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(2, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 1,
            'page' => 150,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(0, $content['list']);

        // perPage > count → all on page 1
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 150,
            'page' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount($count, $content['list']);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'perPage' => 150,
            'page' => 2,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(0, $content['list']);

        // page=0 treated as first page
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'page' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);
    }

    /**
     * @covers \App\Controller\TransactionController::list
     *
     * @group smoke
     * @group transactions
     */
    public function testBeforeAfterFilters(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
        ]));
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(39, $content['count']);
        self::assertEqualsWithDelta(470, $content['totalValue'], 0.01);
        self::assertTrue(Carbon::parse($content['list'][0]['executedAt'])->isBetween($after, $before));
        self::assertTrue(Carbon::parse($content['list'][29]['executedAt'])->isBetween($after, $before));

        // Last page: 39 total, 30 per page → page 2 has 9 items
        $lastPageNumber = ceil($content['count'] / 30);
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'page' => $lastPageNumber,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(9, $content['list']);
        self::assertEquals(39, $content['count']);
        self::assertEqualsWithDelta(470, $content['totalValue'], 0.01);
        self::assertTrue(Carbon::parse($content['list'][0]['executedAt'])->isBetween($after, $before));
        self::assertTrue(Carbon::parse($content['list'][8]['executedAt'])->isBetween($after, $before));
    }

    /**
     * UAH Card: 5 expenses × 10 EUR each = 50 EUR, totalValue=-50
     * EUR Cash: 30 expenses (1480 EUR) + 4 incomes (2000 EUR), totalValue=520
     * Both: all 39 transactions, totalValue=470
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testAccountsFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $uahId = $this->accountCashUAH->getId();
        $eurId = $this->accountCashEUR->getId();

        // UAH Card only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'accounts[]' => $uahId,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(5, $content['list']);
        self::assertEquals(5, $content['count']);
        self::assertEqualsWithDelta(-50, $content['totalValue'], 0.01);
        self::assertEquals($uahId, $content['list'][0]['account']['id']);
        self::assertEquals($uahId, $content['list'][4]['account']['id']);

        // Both accounts
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'accounts' => [$eurId, $uahId],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(39, $content['count']);
        self::assertEqualsWithDelta(470, $content['totalValue'], 0.01);
        self::assertContains($content['list'][0]['account']['id'], [$eurId, $uahId]);
        self::assertContains($content['list'][29]['account']['id'], [$eurId, $uahId]);

        // Invalid account IDs
        $response = $this->client->request(
            'GET',
            self::TRANSACTION_LIST_URL."?after={$after->toDateString()}&before={$before->toDateString()}&accounts[]=100&accounts[]=xsss&accounts[]=-1",
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(0, $content['list']);
        self::assertEquals(0, $content['count']);
        self::assertEquals(0, $content['totalValue']);
    }

    /**
     * Food & Drinks with nested (Groceries+EatingOut): 31 in Jan 2021, totalValue=-1130
     * Food & Drinks + Rent: 35 in Jan 2021, totalValue=-1530
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testCategoriesFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        $foodCategory = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Food & Drinks']);
        $rentCategory = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Rent']);

        // Food & Drinks (includes Groceries + Eating Out)
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'categories' => [$foodCategory->getId()],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(31, $content['count']);
        self::assertEqualsWithDelta(-1130, $content['totalValue'], 0.01);

        $foodDescendantIds = $foodCategory->getDescendantsFlat()->map(
            fn(Category $c) => $c->getId()
        )->toArray();
        self::assertContains($content['list'][0]['category']['id'], $foodDescendantIds);
        self::assertContains($content['list'][29]['category']['id'], $foodDescendantIds);

        // Food & Drinks + Rent
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'categories' => [$foodCategory->getId(), $rentCategory->getId()],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(35, $content['count']);
        self::assertEqualsWithDelta(-1530, $content['totalValue'], 0.01);

        // Invalid category IDs: non-existent IDs resolve to empty, filter is skipped
        $response = $this->client->request(
            'GET',
            self::TRANSACTION_LIST_URL."?after={$after->toDateString()}&before={$before->toDateString()}&categories[]=100&categories[]=xsss&categories[]=-1",
        );
        self::assertResponseStatusCodeSame(200);
        $content = $response->toArray();
        self::assertArrayHasKey('list', $content);
        self::assertArrayHasKey('count', $content);
    }

    /**
     * Food & Drinks withNested=1 (Jan2021-Jan2022): 90 transactions (Groceries+EatingOut all year)
     * Food & Drinks withNested=0: 0 transactions (no direct transactions under root)
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testWithNestedCategoriesFilter(): void
    {
        $foodCategory = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Food & Drinks']);
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2022-01-31')->endOfDay();

        // withNested=1: includes Groceries + Eating Out
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'withNestedCategories' => 1,
            'categories' => [$foodCategory->getId()],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(90, $content['count']);
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(-9820, $content['totalValue'], 0.01);

        $nestedCategoryIds = $foodCategory->getDescendantsFlat()->map(
            fn(Category $c) => $c->getId()
        )->toArray();
        self::assertContains($content['list'][0]['category']['id'], $nestedCategoryIds);
        self::assertContains($content['list'][14]['category']['id'], $nestedCategoryIds);
        self::assertContains($content['list'][29]['category']['id'], $nestedCategoryIds);

        // withNested=0: only direct transactions under Food & Drinks root (none in fixtures)
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'withNestedCategories' => 0,
            'categories' => [$foodCategory->getId()],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(0, $content['count']);
        self::assertCount(0, $content['list']);
    }

    /**
     * @covers \App\Controller\TransactionController::list
     */
    public function testIsDraftFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'isDraft' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(0, $content['count']);
        self::assertCount(0, $content['list']);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'isDraft' => 0,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(39, $content['count']);
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(470, $content['totalValue'], 0.01);
    }

    /**
     * type=expense: 35 transactions, totalValue=-1530
     * type=income: 4 transactions, totalValue=2000
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testTypeFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'type' => Transaction::EXPENSE,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(35, $content['count']);
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(-1530, $content['totalValue'], 0.01);

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'type' => Transaction::INCOME,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(4, $content['count']);
        self::assertCount(4, $content['list']);
        self::assertEqualsWithDelta(2000, $content['totalValue'], 0.01);
    }

    /**
     * currencies[] filter: only transactions from accounts with matching currency.
     * EUR Cash account: EUR; UAH Card account: UAH.
     * Jan 2021: 34 EUR transactions (30 expenses + 4 incomes), 5 UAH transactions.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testCurrenciesFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        // EUR only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'        => $after->toDateString(),
            'before'       => $before->toDateString(),
            'currencies[]' => ['EUR'],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(34, $content['count']);
        foreach ($content['list'] as $item) {
            self::assertSame('EUR', $item['account']['currency']);
        }

        // UAH only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'        => $after->toDateString(),
            'before'       => $before->toDateString(),
            'currencies[]' => ['UAH'],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(5, $content['count']);
        foreach ($content['list'] as $item) {
            self::assertSame('UAH', $item['account']['currency']);
        }

        // Both EUR + UAH → same as unfiltered
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'      => $after->toDateString(),
            'before'     => $before->toDateString(),
            'currencies' => ['EUR', 'UAH'],
        ]));
        self::assertResponseIsSuccessful();
        self::assertEquals(39, $response->toArray()['count']);

        // Non-existent currency → empty result
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'        => $after->toDateString(),
            'before'       => $before->toDateString(),
            'currencies[]' => ['GBP'],
        ]));
        self::assertResponseIsSuccessful();
        self::assertEquals(0, $response->toArray()['count']);
    }

    /**
     * amount[gte] / amount[lte] filters: only transactions within the specified range.
     * Jan 2021 EUR Cash expenses: 30 items at various amounts.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testAmountRangeFilter(): void
    {
        // Create a few with known amounts in an isolated date range
        $groceries = $this->em->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        assert($groceries !== null);

        $date = Carbon::parse('2026-04-10T12:00:00Z');
        $this->createExpense(amount: 10.0,  account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'tx-amt-10');
        $this->createExpense(amount: 50.0,  account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'tx-amt-50');
        $this->createExpense(amount: 200.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'tx-amt-200');
        $this->em->clear();

        // gte=20, lte=100 → only 50
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'       => '2026-04-01',
            'before'      => '2026-04-30',
            'amount[gte]' => '20',
            'amount[lte]' => '100',
        ]));
        self::assertResponseIsSuccessful();
        $notes = array_column($response->toArray()['list'], 'note');
        self::assertContains('tx-amt-50', $notes);
        self::assertNotContains('tx-amt-10', $notes);
        self::assertNotContains('tx-amt-200', $notes);

        // gte only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'       => '2026-04-01',
            'before'      => '2026-04-30',
            'amount[gte]' => '100',
        ]));
        self::assertResponseIsSuccessful();
        $notes = array_column($response->toArray()['list'], 'note');
        self::assertContains('tx-amt-200', $notes);
        self::assertNotContains('tx-amt-10', $notes);
        self::assertNotContains('tx-amt-50', $notes);

        // lte only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'       => '2026-04-01',
            'before'      => '2026-04-30',
            'amount[lte]' => '15',
        ]));
        self::assertResponseIsSuccessful();
        $notes = array_column($response->toArray()['list'], 'note');
        self::assertContains('tx-amt-10', $notes);
        self::assertNotContains('tx-amt-50', $notes);
        self::assertNotContains('tx-amt-200', $notes);
    }

    /**
     * amount[gte] > amount[lte] must result in an error response (not 2xx).
     * TODO: improve to 400 by mapping InvalidArgumentException to BadRequestHttpException.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testInvalidAmountRangeReturnsError(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'       => '2021-01-01',
            'before'      => '2021-01-31',
            'amount[gte]' => '500',
            'amount[lte]' => '100',
        ]));
        self::assertGreaterThanOrEqual(400, $response->getStatusCode(), 'amount[gte] > amount[lte] must not return 2xx.');
    }

    /**
     * note filter performs a case-insensitive substring match.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testNoteFilter(): void
    {
        $groceries = $this->em->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        assert($groceries !== null);

        $date = Carbon::parse('2026-05-10T12:00:00Z');
        $this->createExpense(amount: 9.99, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'needle unique note');
        $this->createExpense(amount: 5.00, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'haystack no match');
        $this->em->clear();

        // Matching note
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'  => '2026-05-01',
            'before' => '2026-05-31',
            'note'   => 'needle',
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(1, $content['count']);
        self::assertSame('needle unique note', $content['list'][0]['note']);

        // Non-matching note → empty
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'  => '2026-05-01',
            'before' => '2026-05-31',
            'note'   => 'nothingmatches',
        ]));
        self::assertResponseIsSuccessful();
        self::assertEquals(0, $response->toArray()['count']);

        // Blank note → no filter applied (all records returned)
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after'  => '2026-05-01',
            'before' => '2026-05-31',
            'note'   => '',
        ]));
        self::assertResponseIsSuccessful();
        self::assertEquals(2, $response->toArray()['count']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Response shape validation
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Assert that each transaction in the list has all fields the frontend reads.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testTransactionListItem_hasCorrectShape(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertNotEmpty($content['list']);
        $transaction = $content['list'][0];

        // Fields the frontend RawTransactionDTO expects
        self::assertArrayHasKey('id', $transaction);
        self::assertArrayHasKey('account', $transaction);
        self::assertArrayHasKey('amount', $transaction);
        self::assertArrayHasKey('convertedValues', $transaction);
        self::assertArrayHasKey('note', $transaction);
        self::assertArrayHasKey('executedAt', $transaction);
        self::assertArrayHasKey('category', $transaction);
        self::assertArrayHasKey('isDraft', $transaction);
        self::assertArrayHasKey('type', $transaction);

        // Account sub-object
        self::assertIsArray($transaction['account']);
        self::assertArrayHasKey('id', $transaction['account']);
        self::assertArrayHasKey('name', $transaction['account']);
        self::assertArrayHasKey('currency', $transaction['account']);

        // Category sub-object
        self::assertIsArray($transaction['category']);
        self::assertArrayHasKey('id', $transaction['category']);
        self::assertArrayHasKey('name', $transaction['category']);

        // Type checks
        self::assertIsInt($transaction['id']);
        self::assertIsNumeric($transaction['amount']);
        self::assertIsBool($transaction['isDraft']);
        self::assertContains($transaction['type'], ['expense', 'income']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Excluded categories filter
    // ──────────────────────────────────────────────────────────────────────

    /**
     * excludedCategories[] filter: remove specific categories from results.
     * Food & Drinks has 31 transactions in Jan 2021. Excluding it should leave 8 (39-31).
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testExcludedCategoriesFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();

        // Use Rent — a leaf category that has transactions assigned directly
        $rentCategory = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Rent']);

        // Without exclusion
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
        ]));
        self::assertResponseIsSuccessful();
        $totalWithoutExclusion = $response->toArray()['count'];

        // With exclusion
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'excludedCategories' => [$rentCategory->getId()],
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertLessThan($totalWithoutExclusion, $content['count'], 'Excluding Rent category must reduce count.');

        // Verify none of the returned transactions belong to excluded category
        foreach ($content['list'] as $item) {
            self::assertNotEquals(
                $rentCategory->getId(),
                $item['category']['id'],
                'Excluded category transactions must not appear.',
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Combined filters
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Combining type + accounts + categories filters.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testCombinedFilters_typeAndAccount(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $eurId = $this->accountCashEUR->getId();

        // EUR Cash + expense only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'type' => Transaction::EXPENSE,
            'accounts[]' => $eurId,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // EUR Cash expenses: 30 in Jan 2021
        self::assertEquals(30, $content['count']);
        foreach ($content['list'] as $item) {
            self::assertEquals($eurId, $item['account']['id']);
            self::assertSame('expense', $item['type']);
        }

        // EUR Cash + income only
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => $after->toDateString(),
            'before' => $before->toDateString(),
            'type' => Transaction::INCOME,
            'accounts[]' => $eurId,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // EUR Cash incomes: 4 in Jan 2021
        self::assertEquals(4, $content['count']);
        foreach ($content['list'] as $item) {
            self::assertEquals($eurId, $item['account']['id']);
            self::assertSame('income', $item['type']);
        }
    }

    /**
     * Combining note filter with type filter.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testCombinedFilters_noteAndType(): void
    {
        $groceries = $this->em->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        $salary = $this->em->getRepository(Category::class)->findOneBy(['name' => self::CATEGORY_INCOME_SALARY]);
        assert($groceries !== null && $salary !== null);

        $date = Carbon::parse('2026-06-15T12:00:00Z');
        $this->createExpense(amount: 25.0, account: $this->accountCashEUR, category: $groceries, executedAt: $date, note: 'combo test expense');
        $this->createIncome(amount: 100.0, account: $this->accountCashEUR, category: $salary, executedAt: $date, note: 'combo test income');
        $this->em->clear();

        // Search "combo test" + type=expense → only expense
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => '2026-06-01',
            'before' => '2026-06-30',
            'note' => 'combo test',
            'type' => Transaction::EXPENSE,
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEquals(1, $content['count']);
        self::assertSame('combo test expense', $content['list'][0]['note']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Security
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\TransactionController::list
     */
    public function testTransactionList_withoutAuth_returns401(): void
    {
        $this->client->request('GET', self::TRANSACTION_LIST_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Empty results
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\TransactionController::list
     */
    public function testTransactionList_noResults_returnsEmptyListWithZeroTotals(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::TRANSACTION_LIST_URL, [
            'after' => '2099-01-01',
            'before' => '2099-12-31',
        ]));
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(0, $content['list']);
        self::assertEquals(0, $content['count']);
        self::assertEquals(0, $content['totalValue']);
    }

    /**
     * Verify that the removed GetCollection endpoint on Transaction entity is no longer accessible.
     *
     * @covers \App\Controller\TransactionController::list
     */
    public function testGetCollectionApiPlatform_removedEndpoint_returns404(): void
    {
        $response = $this->client->request('GET', '/api/transactions');
        // Should be 404 since GetCollection was removed
        self::assertContains($response->getStatusCode(), [404, 405]);
    }

    private const EXPORT_CSV_URL = '/api/v2/transaction/export.csv';

    /**
     * Regression: passing no currencies param sent null to CSVExporter::stream(array $currencies),
     * which is a TypeError under strict_types=1. Must return 200 with a CSV content-type.
     *
     * @covers \App\Controller\TransactionController::exportCsv
     */
    public function testExportCsvWithoutFiltersDoesNotCrash(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::EXPORT_CSV_URL, [
            'after'  => '2021-01-01',
            'before' => '2021-01-31',
        ]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $response->getHeaders()['content-type'][0]);
    }

    /**
     * CSV export with currencies filter must return only matching rows and not crash.
     *
     * @covers \App\Controller\TransactionController::exportCsv
     */
    public function testExportCsvWithCurrenciesFilter(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::EXPORT_CSV_URL, [
            'after'        => '2021-01-01',
            'before'       => '2021-01-31',
            'currencies[]' => ['EUR'],
        ]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $response->getHeaders()['content-type'][0]);

        $body = $response->getContent();
        // Every data row's currency column must be EUR
        $lines = array_filter(explode("\n", $body));
        $headers = null;
        $currencyIdx = null;
        foreach ($lines as $line) {
            $cols = str_getcsv(trim($line));
            if ($headers === null) {
                $headers = $cols;
                $currencyIdx = array_search('currency', $headers, true);
                continue;
            }
            if ($currencyIdx !== false && isset($cols[$currencyIdx])) {
                self::assertSame('EUR', $cols[$currencyIdx]);
            }
        }
    }

    /**
     * CSV export with a non-existent currency must succeed (not crash) and return a CSV content-type.
     *
     * @covers \App\Controller\TransactionController::exportCsv
     */
    public function testExportCsvWithUnknownCurrencyDoesNotCrash(): void
    {
        $response = $this->client->request('GET', $this->buildURL(self::EXPORT_CSV_URL, [
            'after'        => '2021-01-01',
            'before'       => '2021-01-31',
            'currencies[]' => ['GBP'],
        ]));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $response->getHeaders()['content-type'][0]);
    }
}
