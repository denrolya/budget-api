<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Tests\BaseApiTest;
use Carbon\Carbon;

/**
 * @group smoke
 */
final class ExpenseCRUDTest extends BaseApiTest
{
    private const ACCOUNT_MONO_UAH_ID = 10;
    private const ACCOUNT_CASH_EUR_ID = 2;
    private const CATEGORY_GROCERIES = 'Groceries';

    private Account $testAccount;

    private ExpenseCategory $testCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_MONO_UAH_ID);
        $this->testCategory = $this->em->getRepository(ExpenseCategory::class)->findOneByName(self::CATEGORY_GROCERIES);
    }

    public function testCreateExpenseUpdatesAccountAndCategory(): void
    {
        $executionDate = Carbon::now()->startOfDay();

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: $executionDate,
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(465, $this->testCategory->getTransactionsCount(false));
    }

    public function testCreateExpenseSavedWithConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(1))->method('convert');

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();

        self::assertEquals($transaction->getAmount(), $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEquals(4, $transaction->getConvertedValue('USD'));
        self::assertEquals(1000, $transaction->getConvertedValue('HUF'));
        self::assertEqualsWithDelta(
            0.0003333333333333333,
            $transaction->getConvertedValue('BTC'),
            0.0000000000000001
        );
    }

    public function testUpdateExpenseAmountUpdatesAccountAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals($transaction->getAmount(), $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEquals(4, $transaction->getConvertedValue('USD'));
        self::assertEquals(1000, $transaction->getConvertedValue('HUF'));
        self::assertEqualsWithDelta(
            0.0003333333333333333,
            $transaction->getConvertedValue('BTC'),
            0.0000000000000001
        );

        $transaction->setAmount(50);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11228.35, $this->testAccount->getBalance(), 0.01);
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

    public function testUpdateExpenseAccountUpdatesAccountsBalancesAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $endAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_CASH_EUR_ID);

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(5429.94, $endAccount->getBalance(), 0.01);
        self::assertEquals(552, $endAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->testAccount->getCurrency()));
        self::assertEquals(4, $transaction->getConvertedValue('USD'));

        $transaction->setAccount($endAccount);
        $this->em->flush();
        $this->em->refresh($this->testAccount);
        $this->em->refresh($endAccount);

        self::assertEquals(100, $transaction->getConvertedValue($endAccount->getCurrency()));
        self::assertEquals(3000, $transaction->getConvertedValue('UAH'));
        self::assertEquals(120, $transaction->getConvertedValue('USD'));

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(553, $endAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEqualsWithDelta(5329.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateExpenseAccountAndAmountUpdatesAccountBalancesAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $endAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_CASH_EUR_ID);

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(5429.94, $endAccount->getBalance(), 0.01);
        self::assertEquals(552, $endAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->testAccount->getCurrency()));
        self::assertEquals(4, $transaction->getConvertedValue('USD'));

        $transaction->setAccount($endAccount)->setAmount(50);
        $this->em->flush();
        $this->em->refresh($this->testAccount);
        $this->em->refresh($endAccount);

        self::assertEquals(50, $transaction->getConvertedValue($endAccount->getCurrency()));
        self::assertEquals(1500, $transaction->getConvertedValue('UAH'));
        self::assertEquals(60, $transaction->getConvertedValue('USD'));

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(553, $endAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEqualsWithDelta(5379.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateExpenseExecutedAtDoesNotChangeAccountBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(1))->method('convert');

        $executionDate = Carbon::now();

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: $executionDate,
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);
        $convertedValues = $transaction->getConvertedValues();

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->testAccount->getBalance(), 0.01);

        $transaction->setExecutedAt($executionDate->subMonth());
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals($convertedValues, $transaction->getConvertedValues());
    }

    public function testDeleteExpenseUpdatesAccountAndCategory(): void
    {
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();

        self::assertEqualsWithDelta(11178.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(465, $this->testCategory->getTransactionsCount(false));

        $this->em->remove($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));
    }

    public function testCreateExpenseWithCompensationsProperlyCalculatesValueAndAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testUpdateExpenseWithCompensationsAmountRecalculatesValueAndAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteExpenseWithCompensationsRemovesCompensationsAndUpdatesAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testAddCompensationToExpenseRecalculatesValueAndAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testUpdateCompensationToExpenseRecalculatesValueAndAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteCompensationToExpenseRecalculatesValueAndAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    private function createExpense($amount, $account, $category, $executedAt, $note): Expense
    {
        $expense = new Expense();
        $expense
            ->setAccount($account)
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setOwner($account->getOwner())
            ->setNote($note);

        return $expense;
    }
}
