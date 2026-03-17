<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Tests\BaseApiTestCase;
use Carbon\Carbon;

/**
 * @group smoke
 */
final class CompensationsFeatureTest extends BaseApiTestCase
{
    protected bool $useAssetsManagerMock = true;

    protected const CATEGORY_GROCERIES = 'Groceries';
    protected const CATEGORY_COMPENSATION = 'Compensation';

    private ExpenseCategory $testCategory;

    private IncomeCategory $compensationCategory;

    private function getCompensation(Expense $expense, int $index): Income
    {
        $compensation = $expense->getCompensations()[$index];
        \assert($compensation instanceof Income);

        return $compensation;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $category = $this->entityManager()->getRepository(ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_GROCERIES]);
        \assert($category instanceof ExpenseCategory);
        $this->testCategory = $category;
        $compensation = $this->entityManager()->getRepository(IncomeCategory::class)->findOneBy(['name' => self::CATEGORY_COMPENSATION]);
        \assert($compensation instanceof IncomeCategory);
        $this->compensationCategory = $compensation;
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * An expense created together with compensations must:
     * - Update the account balance by the net amount (expense − sum of compensations).
     * - Increment the account transaction count by 1 (expense) + N (compensations).
     * - Persist the expense's gross converted value (independent of compensations).
     * - Store individual converted values on each compensation income record.
     */
    public function testCreateExpenseWithCompensationsProperlyCalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(3))->method('convert');
        $executionDate = Carbon::now()->startOfDay();

        $balanceBefore = (float) $this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();
        $groceriesCountBefore = $this->testCategory->getTransactionsCount(false);
        $compensationCountBefore = $this->compensationCategory->getTransactionsCount(false);

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $executionDate->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
                'note' => 'Test transaction',
                'compensations' => [
                    [
                        'amount' => '25.0',
                        'executedAt' => $executionDate->toIso8601String(),
                        'category' => $this->iri($this->compensationCategory),
                        'account' => $this->iri($this->accountCashUAH),
                        'note' => 'Test compensation',
                    ],
                    [
                        'amount' => '25.0',
                        'executedAt' => $executionDate->toIso8601String(),
                        'category' => $this->iri($this->compensationCategory),
                        'account' => $this->iri($this->accountCashUAH),
                        'note' => 'Test compensation',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore - 100 + 25 + 25, (float) $this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($groceriesCountBefore + 1, $this->testCategory->getTransactionsCount(false));
        self::assertEquals($compensationCountBefore + 2, $this->compensationCategory->getTransactionsCount(false));

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->entityManager()->getRepository(Expense::class)->find($content['id']);
        self::assertInstanceOf(Expense::class, $transaction);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->accountCashUAH, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->accountCashUAH->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));
        self::assertCount(2, $transaction->getCompensations());

        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);
    }

    // ── Update expense ────────────────────────────────────────────────────────

    /**
     * Updating the expense amount recalculates the expense's gross converted
     * value and adjusts the account balance by the difference.
     * Compensation converted values are unaffected.
     *
     * Scenario: expense 100→50 with comps [25, 25]. Balance increases by 50.
     */
    public function testUpdateExpenseWithCompensationsAmountRecalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ],
        );

        $transactionId = $transaction->getId();
        $balanceAfterCreate = (float) $this->accountCashUAH->getBalance();
        $countAfterCreate = $this->accountCashUAH->getTransactionsCount();

        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);

        $response = $this->client->request('PUT', self::TRANSACTION_URL . '/' . $transactionId, [
            'json' => [
                'amount' => '50',
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals($countAfterCreate, $this->accountCashUAH->getTransactionsCount());
        // expense reduced 100→50, balance increases by 50
        self::assertEqualsWithDelta($balanceAfterCreate + 50, (float) $this->accountCashUAH->getBalance(), 0.01);

        $this->entityManager()->clear();
        $transaction = $this->entityManager()->getRepository(Expense::class)->find($transactionId);
        \assert($transaction instanceof Expense);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(50, $transaction->getAmount());
        self::assertEqualsWithDelta(1.67, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
    }

    /**
     * Adding a new compensation while also changing the expense amount correctly
     * updates the balance and the gross converted value for the expense.
     *
     * Scenario: expense 100→150 + comp[25] + comp[25] + comp[10].
     * Balance delta from captured point: −50 (expense increase) + 10 (new comp) = −40.
     */
    public function testAddCompensationToExpenseAndUpdateAmountRecalculatesValuesAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(5))->method('convert');
        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ],
        );

        $countAfterCreate = $this->accountCashUAH->getTransactionsCount();
        $balanceBefore = (float) $this->accountCashUAH->getBalance();

        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);

        $this->client->request('PUT', self::TRANSACTION_URL . '/' . $transaction->getId(), [
            'json' => [
                'compensations' => [
                    ...$transaction->getCompensations()->map(static function (Income $compensation) use ($transaction) {
                        return [
                            'id' => '/api/transactions/' . $compensation->getId(),
                            '@type' => 'Income',
                            'originalExpense' => '/api/expenses/' . $transaction->getId(),
                        ];
                    })->toArray(),
                    [
                        'amount' => '10',
                        'executedAt' => Carbon::now()->toIso8601String(),
                        'category' => $this->iri($this->compensationCategory),
                        'account' => $this->iri($this->accountCashUAH),
                        'type' => 'income',
                        'note' => 'New Compensation',
                    ],
                ],
                'amount' => '150',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $transaction = $this->entityManager()->getRepository(Expense::class)->find($transaction->getId());
        \assert($transaction instanceof Expense);
        self::assertEquals($countAfterCreate + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(3, $transaction->getCompensations());
        // $balanceBefore captured after create; expense +50, new comp +10; delta = -50+10 = -40
        self::assertEqualsWithDelta($balanceBefore - 40, (float) $this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals(150, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(5, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(6, $transaction->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.33, $this->getCompensation($transaction, 2)->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.4, $this->getCompensation($transaction, 2)->getConvertedValue('USD'), 0.01);
    }

    // ── Update compensation ───────────────────────────────────────────────────

    /**
     * Updating a compensation's amount adjusts the account balance.
     * The parent expense's converted value is unaffected (stores gross).
     *
     * Scenario: expense 100 with comps [25, 25]. Update comp[1] to 50.
     * Account gains 25 more (comp increased). Expense convertedValues unchanged.
     */
    public function testUpdateCompensationAmountRecalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');

        $balanceBefore = (float) $this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ],
        );

        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);

        $this->client->request(
            'PUT',
            self::TRANSACTION_URL . '/' . $this->getCompensation($transaction, 1)->getId(),
            [
                'json' => [
                    'amount' => '50',
                    'note' => 'Updated Compensation',
                ],
            ],
        );
        self::assertResponseIsSuccessful();

        $transaction = $this->entityManager()->getRepository(Expense::class)->find($transaction->getId());
        \assert($transaction instanceof Expense);
        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        // balance: before - 100 + 25 + 25 + 25 (comp increase) = before - 25
        self::assertEqualsWithDelta($balanceBefore - 25, (float) $this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals('Updated Compensation', $this->getCompensation($transaction, 1)->getNote());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1.66, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(2, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(25, $this->getCompensation($transaction, 0)->getConvertedValue('UAH'), 0.01);
        self::assertEqualsWithDelta(50, $this->getCompensation($transaction, 1)->getConvertedValue('UAH'), 0.01);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Deleting an expense that has compensations must cascade-remove all
     * linked compensation income records and fully restore the original balance
     * (as if neither the expense nor the compensations ever existed).
     */
    public function testDeleteExpenseWithCompensationsRemovesCompensationsAndUpdatesAccountBalances(): void
    {
        $balanceBefore = (float) $this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();
        $groceriesCountBefore = $this->testCategory->getTransactionsCount(false);
        $compensationCountBefore = $this->compensationCategory->getTransactionsCount(false);

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ],
        );

        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);

        $this->client->request('DELETE', self::TRANSACTION_URL . '/' . $transaction->getId());
        self::assertResponseIsSuccessful();

        self::assertEquals($countBefore, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore, (float) $this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($groceriesCountBefore, $this->testCategory->getTransactionsCount(false));
        self::assertEquals($compensationCountBefore, $this->compensationCategory->getTransactionsCount(false));
    }

    /**
     * Deleting one of two compensations adjusts the account balance.
     * The parent expense's converted value is unaffected (stores gross).
     *
     * Scenario: expense 100 with comps [25, 25]. Delete comp[1].
     * Account loses the 25 credit. Expense convertedValues unchanged at 100.
     */
    public function testDeleteCompensationToExpenseRecalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(3))->method('convert');

        $balanceBefore = (float) $this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Compensation that is gonna be removed',
                ],
            ],
        );

        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(4, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 1)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 1)->getConvertedValue('USD'), 0.01);

        $this->client->request(
            'DELETE',
            self::TRANSACTION_URL . '/' . $this->getCompensation($transaction, 1)->getId(),
        );
        self::assertResponseIsSuccessful();

        $transaction = $this->entityManager()->getRepository(Expense::class)->find($transaction->getId());
        \assert($transaction instanceof Expense);
        self::assertCount(1, $transaction->getCompensations());
        // after create: balance - 50 net; after delete comp25: balance - 75
        self::assertEqualsWithDelta($balanceBefore - 75, (float) $this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(0.83, $this->getCompensation($transaction, 0)->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $this->getCompensation($transaction, 0)->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(25, $this->getCompensation($transaction, 0)->getConvertedValue('UAH'), 0.01);
    }

    /**
     * Deleting the last (only) compensation correctly restores the account
     * balance to the full expense amount. The compensation's credit is reversed,
     * so the account is debited for the full expense once again.
     *
     * Scenario: expense 100 with single comp 40. Net balance effect = −60.
     * Delete the comp → balance effect returns to −100.
     */
    public function testDeleteLastCompensationRestoresAccountBalance(): void
    {
        $this->mockAssetsManager->expects(self::atLeastOnce())->method('convert');

        $balanceBefore = (float) $this->accountCashUAH->getBalance();

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountCashUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 40,
                    'account' => $this->accountCashUAH,
                    'executedAt' => Carbon::now(),
                    'note' => 'Only compensation',
                ],
            ],
        );

        self::assertCount(1, $transaction->getCompensations());
        self::assertEquals(100, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta($balanceBefore - 60, (float) $this->accountCashUAH->getBalance(), 0.01);

        $this->client->request(
            'DELETE',
            self::TRANSACTION_URL . '/' . $this->getCompensation($transaction, 0)->getId(),
        );
        self::assertResponseIsSuccessful();

        $transaction = $this->entityManager()->getRepository(Expense::class)->find($transaction->getId());
        \assert($transaction instanceof Expense);
        self::assertCount(0, $transaction->getCompensations());
        // Balance decreases by the additional 40 that was previously covered by the compensation
        self::assertEqualsWithDelta($balanceBefore - 100, (float) $this->accountCashUAH->getBalance(), 0.01);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    /**
     * A compensation linked to a different account than the expense updates
     * both account balances independently: the expense account is debited,
     * and the compensation account is credited.
     *
     * Scenario: expense 100 on UAH account; compensation 60 on EUR account.
     * UAH account net: −100 + 0 = −100 (compensation not on this account).
     * EUR account net: +60 (compensation credited here).
     */
    public function testCompensationFromDifferentAccountUpdatesBothAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::atLeastOnce())->method('convert');

        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();

        $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
                'note' => 'Expense on UAH account',
                'compensations' => [
                    [
                        'amount' => '60.0',
                        'executedAt' => Carbon::now()->toIso8601String(),
                        'category' => $this->iri($this->compensationCategory),
                        'account' => $this->iri($this->accountCashEUR),
                        'note' => 'Compensation from EUR account',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // UAH account is debited by the expense
        self::assertEqualsWithDelta($uahBalanceBefore - 100, (float) $this->accountCashUAH->getBalance(), 0.01);
        // EUR account is credited by the compensation
        self::assertEqualsWithDelta($eurBalanceBefore + 60, (float) $this->accountCashEUR->getBalance(), 0.01);
    }

    /**
     * When the sum of compensations exceeds the expense amount, the expense
     * still stores its gross converted value. The account balance reflects
     * the net gain correctly.
     *
     * Scenario: expense 50 with comp 80.
     * Account balance: −50 + 80 = +30 (net credit).
     * Expense convertedValues: 50 (gross, unaffected by compensation).
     */
    public function testCompensationsTotalExceedingExpenseAmountProducesNetCreditBalance(): void
    {
        $this->mockAssetsManager->expects(self::atLeastOnce())->method('convert');

        $balanceBefore = (float) $this->accountCashUAH->getBalance();

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '50.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => $this->iri($this->testCategory),
                'account' => $this->iri($this->accountCashUAH),
                'note' => 'Over-compensated expense',
                'compensations' => [
                    [
                        'amount' => '80.0',
                        'executedAt' => Carbon::now()->toIso8601String(),
                        'category' => $this->iri($this->compensationCategory),
                        'account' => $this->iri($this->accountCashUAH),
                        'note' => 'Over-compensation',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // expense −50, compensation +80 → net +30 on the account
        self::assertEqualsWithDelta($balanceBefore + 30, (float) $this->accountCashUAH->getBalance(), 0.01);

        $content = $response->toArray();
        $transaction = $this->entityManager()->getRepository(Expense::class)->find($content['id']);
        \assert($transaction instanceof Expense);
        // gross converted value — independent of compensations
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
    }
}
