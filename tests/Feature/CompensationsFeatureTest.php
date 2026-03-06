<?php

namespace App\Tests\Feature;

use App\Entity\Expense;
use App\Entity\ExpenseCategory;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->testCategory = $this->em->getRepository(ExpenseCategory::class)->findOneByName(self::CATEGORY_GROCERIES);
        $this->compensationCategory = $this->em->getRepository(IncomeCategory::class)->findOneByName(
            self::CATEGORY_COMPENSATION
        );
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * An expense created together with compensations must:
     * - Update the account balance by the net amount (expense − sum of compensations).
     * - Increment the account transaction count by 1 (expense) + N (compensations).
     * - Persist the expense's net converted value after subtracting each compensation.
     * - Store individual converted values on each compensation income record.
     */
    public function testCreateExpenseWithCompensationsProperlyCalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(9))->method('convert');
        $executionDate = Carbon::now()->startOfDay();

        $balanceBefore = (float)$this->accountCashUAH->getBalance();
        $countBefore = $this->accountCashUAH->getTransactionsCount();
        $groceriesCountBefore = $this->testCategory->getTransactionsCount(false);
        $compensationCountBefore = $this->compensationCategory->getTransactionsCount(false);

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $executionDate->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->accountCashUAH->getId(),
                'note' => 'Test transaction',
                'compensations' => [
                    [
                        'amount' => '25.0',
                        'executedAt' => $executionDate->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->accountCashUAH->getId(),
                        'note' => 'Test compensation',
                    ],
                    [
                        'amount' => '25.0',
                        'executedAt' => $executionDate->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->accountCashUAH->getId(),
                        'note' => 'Test compensation',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore - 100 + 25 + 25, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($groceriesCountBefore + 1, $this->testCategory->getTransactionsCount(false));
        self::assertEquals($compensationCountBefore + 2, $this->compensationCategory->getTransactionsCount(false));

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertInstanceOf(Expense::class, $transaction);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->accountCashUAH, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->accountCashUAH->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));
        self::assertCount(2, $transaction->getCompensations());

        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.66, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);
    }

    // ── Update expense ────────────────────────────────────────────────────────

    /**
     * Updating the expense amount recalculates the net converted value for the
     * expense (expense − compensations) and adjusts the account balance by the
     * difference. Compensation converted values are also recalculated.
     *
     * Scenario: expense 100→50 with comps [25, 25]. Old net = 50 (balance −50).
     * New net = 50−25−25 = 0. Balance increases by 50 (net delta).
     */
    public function testUpdateExpenseWithCompensationsAmountRecalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(10))->method('convert');
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
            ]
        );

        $transactionId = $transaction->getId();
        $balanceAfterCreate = (float)$this->accountCashUAH->getBalance();
        $countAfterCreate = $this->accountCashUAH->getTransactionsCount();

        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.66, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);

        $response = $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transactionId, [
            'json' => [
                'amount' => '50',
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals($countAfterCreate, $this->accountCashUAH->getTransactionsCount());
        // expense reduced 100→50, comps [25,25] unchanged; net went from -50 to 0, balance increases by 50
        self::assertEqualsWithDelta($balanceAfterCreate + 50, (float)$this->accountCashUAH->getBalance(), 0.01);

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($transactionId);
        self::assertInstanceOf(Expense::class, $transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(50, $transaction->getAmount());
        self::assertEqualsWithDelta(0, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEquals(0, $transaction->getConvertedValue('UAH'));
    }

    /**
     * Adding a new compensation while also changing the expense amount correctly
     * updates the balance and the net converted value for all three transactions
     * (expense + 2 existing comps + 1 new comp).
     *
     * Scenario: expense 100→150 + comp[25] + comp[25] + comp[10].
     * Net = 150−60 = 90 UAH. Old net was 50, balance decreases by 40.
     */
    public function testAddCompensationToExpenseAndUpdateAmountRecalculatesValuesAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(13))->method('convert');
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
            ]
        );

        $countAfterCreate = $this->accountCashUAH->getTransactionsCount();
        $balanceBefore = (float)$this->accountCashUAH->getBalance();

        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.66, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transaction->getId(), [
            'json' => [
                'compensations' => [
                    ...$transaction->getCompensations()->map(function ($compensation) use ($transaction) {
                        return [
                            'id' => '/api/transactions/'.$compensation->getId(),
                            '@type' => 'Income',
                            'originalExpense' => '/api/expenses/'.$transaction->getId(),
                        ];
                    })->toArray(),
                    [
                        'amount' => '10',
                        'executedAt' => Carbon::now()->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->accountCashUAH->getId(),
                        'type' => 'income',
                        'note' => 'New Compensation',
                    ],
                ],
                'amount' => '150',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Expense::class)->find($transaction->getId());
        self::assertEquals($countAfterCreate + 1, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(3, $transaction->getCompensations());
        // $balanceBefore captured after create (net=-50, balance=99950); new net=-90; delta=-40
        self::assertEqualsWithDelta($balanceBefore - 40, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals(90, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(3.6, $transaction->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.33, $transaction->getCompensations()[2]->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.4, $transaction->getCompensations()[2]->getConvertedValue('USD'), 0.01);
    }

    // ── Update compensation ───────────────────────────────────────────────────

    /**
     * Updating a compensation's amount triggers a full recalculation of the
     * parent expense's net converted value and adjusts the account balance.
     *
     * Scenario: expense 100 with comps [25, 25]. Update comp[1] to 50.
     * New net = 100−25−50 = 25 UAH. Old net was 50. Balance increases by 25.
     */
    public function testUpdateCompensationAmountRecalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(11))->method('convert');

        $balanceBefore = (float)$this->accountCashUAH->getBalance();
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
            ]
        );

        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.66, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);

        $this->client->request(
            'PUT',
            self::TRANSACTION_URL.'/'.$transaction->getCompensations()[1]->getId(),
            [
                'json' => [
                    'amount' => '50',
                    'note' => 'Updated Compensation',
                ],
            ]
        );
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Expense::class)->find($transaction->getId());
        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        // net: expense100 - comp25 - comp50 = 25 UAH deducted from original balance
        self::assertEqualsWithDelta($balanceBefore - 25, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals('Updated Compensation', $transaction->getCompensations()[1]->getNote());
        self::assertEquals(25, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(0.83, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1.66, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);

        self::assertEqualsWithDelta(25, $transaction->getCompensations()[0]->getConvertedValue('UAH'), 0.01);
        self::assertEqualsWithDelta(50, $transaction->getCompensations()[1]->getConvertedValue('UAH'), 0.01);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Deleting an expense that has compensations must cascade-remove all
     * linked compensation income records and fully restore the original balance
     * (as if neither the expense nor the compensations ever existed).
     */
    public function testDeleteExpenseWithCompensationsRemovesCompensationsAndUpdatesAccountBalances(): void
    {
        $balanceBefore = (float)$this->accountCashUAH->getBalance();
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
            ]
        );

        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.66, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$transaction->getId());
        self::assertResponseIsSuccessful();

        self::assertEquals($countBefore, $this->accountCashUAH->getTransactionsCount());
        self::assertEqualsWithDelta($balanceBefore, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals($groceriesCountBefore, $this->testCategory->getTransactionsCount(false));
        self::assertEquals($compensationCountBefore, $this->compensationCategory->getTransactionsCount(false));
    }

    /**
     * Deleting one of two compensations recalculates the expense net value
     * upward (more of the expense is now uncovered). The account balance
     * reflects the additional net cost.
     *
     * Scenario: expense 100 with comps [25, 25]. Net = 50 (balance −50).
     * Delete comp[1, 25]. New net = 75. Balance decreases by additional 25.
     */
    public function testDeleteCompensationToExpenseRecalculatesValueAndAccountBalances(): void
    {
        $this->mockAssetsManager->expects(self::exactly(10))->method('convert');

        $balanceBefore = (float)$this->accountCashUAH->getBalance();
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
            ]
        );

        self::assertEquals($countBefore + 3, $this->accountCashUAH->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        self::assertEquals(50, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.66, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(2, $transaction->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[1]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[1]->getConvertedValue('USD'), 0.01);

        $this->client->request(
            'DELETE',
            self::TRANSACTION_URL.'/'.$transaction->getCompensations()[1]->getId(),
        );
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Expense::class)->find($transaction->getId());
        self::assertCount(1, $transaction->getCompensations());
        // after create: balance - 50 net; after delete comp25: balance - 50 - 25 = balance - 75
        self::assertEqualsWithDelta($balanceBefore - 75, (float)$this->accountCashUAH->getBalance(), 0.01);
        self::assertEquals(75, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(2.5, $transaction->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(25, $transaction->getCompensations()[0]->getConvertedValue('UAH'), 0.01);
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

        $balanceBefore = (float)$this->accountCashUAH->getBalance();

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
            ]
        );

        // net = 100 − 40 = 60 UAH
        self::assertCount(1, $transaction->getCompensations());
        self::assertEquals(60, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta($balanceBefore - 60, (float)$this->accountCashUAH->getBalance(), 0.01);

        $this->client->request(
            'DELETE',
            self::TRANSACTION_URL.'/'.$transaction->getCompensations()[0]->getId(),
        );
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Expense::class)->find($transaction->getId());
        self::assertCount(0, $transaction->getCompensations());
        // Balance decreases by the additional 40 that was previously covered by the compensation
        self::assertEqualsWithDelta($balanceBefore - 100, (float)$this->accountCashUAH->getBalance(), 0.01);
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

        $uahBalanceBefore = (float)$this->accountCashUAH->getBalance();
        $eurBalanceBefore = (float)$this->accountCashEUR->getBalance();

        $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->accountCashUAH->getId(),
                'note' => 'Expense on UAH account',
                'compensations' => [
                    [
                        'amount' => '60.0',
                        'executedAt' => Carbon::now()->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->accountCashEUR->getId(),
                        'note' => 'Compensation from EUR account',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // UAH account is debited by the expense
        self::assertEqualsWithDelta($uahBalanceBefore - 100, (float)$this->accountCashUAH->getBalance(), 0.01);
        // EUR account is credited by the compensation
        self::assertEqualsWithDelta($eurBalanceBefore + 60, (float)$this->accountCashEUR->getBalance(), 0.01);
    }

    /**
     * When the sum of compensations exceeds the expense amount, the net
     * converted value becomes negative (the expense is more than fully
     * recovered, resulting in a net credit). The account balance reflects
     * this net gain correctly.
     *
     * Scenario: expense 50 with comp 80. Net = 50 − 80 = −30 UAH.
     * Account balance increases by 30 (net credit).
     */
    public function testCompensationsTotalExceedingExpenseAmountProducesNetCreditBalance(): void
    {
        $this->mockAssetsManager->expects(self::atLeastOnce())->method('convert');

        $balanceBefore = (float)$this->accountCashUAH->getBalance();

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '50.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->accountCashUAH->getId(),
                'note' => 'Over-compensated expense',
                'compensations' => [
                    [
                        'amount' => '80.0',
                        'executedAt' => Carbon::now()->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->accountCashUAH->getId(),
                        'note' => 'Over-compensation',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        // expense −50, compensation +80 → net +30 on the account
        self::assertEqualsWithDelta($balanceBefore + 30, (float)$this->accountCashUAH->getBalance(), 0.01);

        $content = $response->toArray();
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        // net converted value is negative: expense covered + surplus
        self::assertEquals(-30, $transaction->getConvertedValue('UAH'));
    }
}
