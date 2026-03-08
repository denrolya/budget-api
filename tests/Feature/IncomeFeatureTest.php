<?php

namespace App\Tests\Feature;

use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Tests\BaseApiTestCase;
use Carbon\Carbon;

/**
 * @group smoke
 */
final class IncomeFeatureTest extends BaseApiTestCase
{
    protected bool $useAssetsManagerMock = true;

    private IncomeCategory $testCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $category = $this->em->getRepository(IncomeCategory::class)->findOneBy(['name' => self::CATEGORY_INCOME_SALARY]);
        assert($category instanceof IncomeCategory);
        $this->testCategory = $category;
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * Creating an income increments the account balance by the given amount,
     * increments the account's transaction count, and increments the category
     * transaction count. The returned payload must include an id and all the
     * supplied fields must be persisted correctly.
     */
    public function testCreateIncomeUpdatesAccountAndCategory(): void
    {
        $executionDate = Carbon::now()->startOfDay();

        $balanceBefore = (float)$this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();
        $categoryCountBefore = $this->testCategory->getTransactionsCount(false);

        $response = $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $executionDate->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
                'note' => 'Test transaction',
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals($countBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore + 100, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($categoryCountBefore + 1, $this->testCategory->getTransactionsCount(false));

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Income::class)->find($content['id']);
        self::assertInstanceOf(Income::class, $transaction);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->accountCashUAH, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->accountCashUAH->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));
    }

    /**
     * On creation the income's converted values are calculated and stored
     * for every configured currency. The values are based on the static
     * exchange rates loaded by the test fixtures.
     *
     * UAH is the source currency so its converted value equals the raw amount.
     * Rates used (1 UAH =): EUR ≈ 0.0333, USD = 0.04, HUF = 10, BTC ≈ 0.0000033.
     */
    public function testCreateIncomeSavedWithConvertedValues(): void
    {
        $response = $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
                'note' => 'Test transaction',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        self::assertEquals($content['amount'], $content['convertedValues']['UAH']);
        self::assertEqualsWithDelta(3.33, $content['convertedValues']['EUR'], 0.01);
        self::assertEquals(4, $content['convertedValues']['USD']);
        self::assertEquals(1000, $content['convertedValues']['HUF']);
        self::assertEqualsWithDelta(
            0.0003333333333333333,
            $content['convertedValues']['BTC'],
            0.0000000000000001
        );
    }

    /**
     * isDraft is a display/planning flag only. A draft income is still fully
     * persisted and its amount is added to the account balance immediately,
     * exactly as a non-draft income would be.
     */
    public function testCreateDraftIncomeStillUpdatesAccountBalance(): void
    {
        $balanceBefore = (float)$this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();

        $response = $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '200.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
                'note' => 'Draft income',
                'isDraft' => true,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        self::assertTrue($content['isDraft']);

        self::assertEquals($countBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore + 200, (float)$this->accountCashUAH->getBalance(), 0.01);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    /**
     * The API must reject incomes with an amount of zero with HTTP 422.
     * Zero-amount incomes carry no financial meaning and should not be stored.
     */
    public function testCreateIncomeWithZeroAmountReturns422(): void
    {
        $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * The API must reject incomes with a negative amount with HTTP 422.
     * Negative amounts are not meaningful for incomes.
     */
    public function testCreateIncomeWithNegativeAmountReturns422(): void
    {
        $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '-50',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    // ── Update ───────────────────────────────────────────────────────────────

    /**
     * Updating the amount triggers a recalculation of converted values
     * and adjusts the account balance by the delta (new minus old amount).
     * The transaction count stays the same — it is an update, not a new record.
     */
    public function testUpdateIncomeAmountUpdatesAccountAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(2))->method('convert');

        $balanceBefore = (float)$this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals($countBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore + 100, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($transaction->getAmount(), $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEquals(4, $transaction->getConvertedValue('USD'));
        self::assertEquals(1000, $transaction->getConvertedValue('HUF'));
        self::assertEqualsWithDelta(
            0.0003333333333333333,
            $transaction->getConvertedValue('BTC'),
            0.0000000000000001
        );

        $transactionId = $transaction->getId();
        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transactionId, [
            'json' => [
                'amount' => '50',
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $transaction = $this->em->getRepository(Income::class)->find($transactionId);
        self::assertNotNull($transaction);

        self::assertEquals(50, $transaction->getAmount());
        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals($countBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore + 50, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($transaction->getAmount(), $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.67, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEquals(2, $transaction->getConvertedValue('USD'));
        self::assertEquals(500, $transaction->getConvertedValue('HUF'));
        self::assertEqualsWithDelta(
            0.00016666666666666666,
            $transaction->getConvertedValue('BTC'),
            0.0000000000000001
        );
    }

    /**
     * Moving an income to a different account reverses the balance effect on
     * the original account and applies it to the new one. Converted values are
     * recalculated based on the new account's currency. The transaction count
     * moves from the old account to the new one.
     */
    public function testUpdateIncomeAccountUpdatesAccountsBalancesAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(2))->method('convert');

        $uahBalanceBefore = (float)$this->accountCashUAH->getBalance();
        $uahCountBefore = $this->accountCashUAH->getTransactionsCount();
        $eurBalanceBefore = (float)$this->accountCashEUR->getBalance();
        $eurCountBefore = $this->accountCashEUR->getTransactionsCount();

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals($uahCountBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->accountCashUAH->getCurrency()));
        self::assertEquals(4, $transaction->getConvertedValue('USD'));

        $transactionId = $transaction->getId();
        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transactionId, [
            'json' => [
                'account' => $this->iri($this->accountCashEUR),
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $transaction = $this->em->getRepository(Income::class)->find($transactionId);
        self::assertNotNull($transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(100, $transaction->getConvertedValue($this->accountCashEUR->getCurrency()));
        self::assertEquals(3000, $transaction->getConvertedValue('UAH'));
        self::assertEquals(120, $transaction->getConvertedValue('USD'));

        self::assertEquals($uahCountBefore, $this->accountCashUAH->getTransactionsCount());
        self::assertEquals($eurCountBefore + 1, $this->accountCashEUR->getTransactionsCount());
        self::assertEqualsWithDelta($uahBalanceBefore, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEqualsWithDelta($eurBalanceBefore + 100, (float)$this->accountCashEUR->getBalance(), 0.01);
    }

    /**
     * When both account and amount are changed in a single request, the system
     * reverses the old balance effect on the original account and applies the
     * new amount to the target account. Converted values are recalculated for
     * the new currency / amount combination.
     */
    public function testUpdateIncomeAccountAndAmountUpdatesAccountBalancesAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(2))->method('convert');

        $uahBalanceBefore = (float)$this->accountCashUAH->getBalance();
        $uahCountBefore = $this->accountCashUAH->getTransactionsCount();
        $eurBalanceBefore = (float)$this->accountCashEUR->getBalance();
        $eurCountBefore = $this->accountCashEUR->getTransactionsCount();

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals($uahCountBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->accountCashUAH->getCurrency()));
        self::assertEquals(4, $transaction->getConvertedValue('USD'));

        $transactionId = $transaction->getId();
        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transactionId, [
            'json' => [
                'account' => $this->iri($this->accountCashEUR),
                'amount' => '50',
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $transaction = $this->em->getRepository(Income::class)->find($transactionId);
        self::assertNotNull($transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(50, $transaction->getConvertedValue($this->accountCashEUR->getCurrency()));
        self::assertEquals(1500, $transaction->getConvertedValue('UAH'));
        self::assertEquals(60, $transaction->getConvertedValue('USD'));

        self::assertEquals($uahCountBefore, $this->accountCashUAH->getTransactionsCount());
        self::assertEquals($eurCountBefore + 1, $this->accountCashEUR->getTransactionsCount());
        self::assertEqualsWithDelta($uahBalanceBefore, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEqualsWithDelta($eurBalanceBefore + 50, (float)$this->accountCashEUR->getBalance(), 0.01);
    }

    /**
     * Changing executedAt triggers a full recalculation of converted values
     * (exchange rates can differ by date), but does NOT change the account
     * balance because the amount and account are unchanged.
     *
     * Note: in the test fixtures exchange rates are static (single date), so
     * the converted values also remain the same — this test asserts both the
     * balance-stability contract and that convert() is called exactly once
     * for the update.
     */
    public function testUpdateIncomeExecutedAtDoesNotChangeAccountBalance(): void
    {
        $this->mockAssetsManager->expects(self::exactly(2))->method('convert');

        $executionDate = Carbon::now();

        $balanceBefore = (float)$this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: $executionDate,
            note: 'Test transaction'
        );

        $convertedValues = $transaction->getConvertedValues();

        self::assertEquals($countBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore + 100, (float)$this->accountCashUAH->getBalance(), 0.01);

        $transactionId = $transaction->getId();
        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transactionId, [
            'json' => [
                'executedAt' => $executionDate->subMonth()->toIso8601String(),
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $transaction = $this->em->getRepository(Income::class)->find($transactionId);
        self::assertNotNull($transaction);

        self::assertEquals($countBefore + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore + 100, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($convertedValues, $transaction->getConvertedValues());
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    /**
     * Deleting an income reverses its balance effect on the account (removes
     * the credited amount) and removes it from both the account's and the
     * category's transaction count. The record must be gone from the database.
     */
    public function testDeleteIncomeUpdatesAccountBalance(): void
    {
        $balanceBefore = (float)$this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();
        $categoryCountBefore = $this->testCategory->getTransactionsCount(false);

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $transactionId = $transaction->getId();

        self::assertEqualsWithDelta($balanceBefore + 100, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($categoryCountBefore + 1, $this->testCategory->getTransactionsCount(false));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$transactionId);
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Income::class)->find($transactionId);
        self::assertNull($transaction);

        self::assertEquals($countBefore, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($categoryCountBefore, $this->testCategory->getTransactionsCount(false));
    }
}
