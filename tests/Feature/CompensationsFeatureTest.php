<?php

namespace App\Tests\Feature;

use App\Entity\Account;
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
    protected const ACCOUNT_MONO_UAH_ID = 10;
    protected const CATEGORY_GROCERIES = 'Groceries';
    protected const CATEGORY_COMPENSATION = 'Compensation';

    private Account $testAccount;

    private ExpenseCategory $testCategory;

    private IncomeCategory $compensationCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_MONO_UAH_ID);
        $this->testCategory = $this->em->getRepository(ExpenseCategory::class)->findOneByName(self::CATEGORY_GROCERIES);
        $this->compensationCategory = $this->em->getRepository(IncomeCategory::class)->findOneByName(
            self::CATEGORY_COMPENSATION
        );
    }

    public function testCreateExpenseWithCompensationsProperlyCalculatesValueAndAccountBalances(): void
    {
        $this->mockFixerService->expects(self::exactly(9))->method('convert');
        $executionDate = Carbon::now()->startOfDay();

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));
        self::assertEquals(301, $this->compensationCategory->getTransactionsCount(false));

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $executionDate->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->testAccount->getId(),
                'note' => 'Test transaction',
                'compensations' => [
                    [
                        'amount' => '25.0',
                        'executedAt' => $executionDate->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->testAccount->getId(),
                        'note' => 'Test compensation',
                    ],
                    [
                        'amount' => '25.0',
                        'executedAt' => $executionDate->toIso8601String(),
                        'category' => (string)$this->compensationCategory->getId(),
                        'account' => (string)$this->testAccount->getId(),
                        'note' => 'Test compensation',
                    ],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals(5519, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11228.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(465, $this->testCategory->getTransactionsCount(false));
        self::assertEquals(303, $this->compensationCategory->getTransactionsCount(false));

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertInstanceOf(Expense::class, $transaction);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->testAccount, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->testAccount->getOwner(), $transaction->getOwner());
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

    public function testUpdateExpenseWithCompensationsAmountRecalculatesValueAndAccountBalances(): void
    {
        $this->mockFixerService->expects(self::exactly(10))->method('convert');
        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ]
        );

        $transactionId = $transaction->getId();

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

        self::assertEquals(5519, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(465, $this->testCategory->getTransactionsCount(false));
        self::assertEquals(303, $this->compensationCategory->getTransactionsCount(false));

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

    public function testDeleteExpenseWithCompensationsRemovesCompensationsAndUpdatesAccountBalances(): void
    {
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));
        self::assertEquals(301, $this->compensationCategory->getTransactionsCount(false));

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
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

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));
        self::assertEquals(301, $this->compensationCategory->getTransactionsCount(false));
    }

    public function testAddCompensationToExpenseAndUpdateAmountRecalculatesValuesAndAccountBalances(): void
    {
        $this->mockFixerService->expects(self::exactly(13))->method('convert');
        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ]
        );

        self::assertEquals(5519, $this->testAccount->getTransactionsCount());
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
                        'account' => (string)$this->testAccount->getId(),
                        'type' => 'income',
                        'note' => 'New Compensation',
                    ],
                ],
                'amount' => '150',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Expense::class)->find($transaction->getId());
        self::assertEquals(5520, $this->testAccount->getTransactionsCount());
        self::assertCount(3, $transaction->getCompensations());
        self::assertEqualsWithDelta(11188.35, $this->testAccount->getBalance(), 0.01);
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

    public function testUpdateCompensationAmountRecalculatesValueAndAccountBalances(): void
    {
        $this->mockFixerService->expects(self::exactly(11))->method('convert');
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
            ]
        );

        self::assertEquals(5519, $this->testAccount->getTransactionsCount());
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
        self::assertEquals(5519, $this->testAccount->getTransactionsCount());
        self::assertCount(2, $transaction->getCompensations());
        self::assertEqualsWithDelta(11253.35, $this->testAccount->getBalance(), 0.01);
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

    public function testDeleteCompensationToExpenseRecalculatesValueAndAccountBalances(): void
    {
        $this->mockFixerService->expects(self::exactly(10))->method('convert');
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction',
            compensations: [
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Test compensation',
                ],
                [
                    'amount' => 25,
                    'account' => $this->testAccount,
                    'executedAt' => Carbon::now(),
                    'note' => 'Compensation that is gonna be removed',
                ],
            ]
        );

        self::assertEquals(5519, $this->testAccount->getTransactionsCount());
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
        self::assertEqualsWithDelta(11203.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(75, $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(2.5, $transaction->getConvertedValue('EUR'), 0.01);

        self::assertEqualsWithDelta(0.83, $transaction->getCompensations()[0]->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(1, $transaction->getCompensations()[0]->getConvertedValue('USD'), 0.01);
        self::assertEqualsWithDelta(25, $transaction->getCompensations()[0]->getConvertedValue('UAH'), 0.01);
    }
}
