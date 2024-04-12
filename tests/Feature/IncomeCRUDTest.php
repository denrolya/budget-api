<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Tests\BaseApiTest;
use Carbon\Carbon;

/**
 * @group smoke
 */
final class IncomeCRUDTest extends BaseApiTest
{
    private const ACCOUNT_MONO_UAH_ID = 10;
    private const ACCOUNT_CASH_EUR_ID = 2;
    private const CATEGORY_SALARY = 'Salary';

    private Account $testAccount;

    private IncomeCategory $testCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_MONO_UAH_ID);
        $this->testCategory = $this->em->getRepository(IncomeCategory::class)->findOneByName(self::CATEGORY_SALARY);
    }

    public function testCreateIncomeUpdatesAccountAndCategory(): void
    {
        $executionDate = Carbon::now()->startOfDay();

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(16, $this->testCategory->getTransactionsCount(false));

        $transaction = $this->createIncome(
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
        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(17, $this->testCategory->getTransactionsCount(false));

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->testAccount, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->testAccount->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));
    }

    public function testCreateIncomeSavedWithConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(1))->method('convert');

        $transaction = $this->createIncome(
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

    public function testUpdateIncomeAmountUpdatesAccountAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $newTransaction = $this->createIncome(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($newTransaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);

        $newTransaction->setAmount(50);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11328.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals($newTransaction->getAmount(), $newTransaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(1.67, $newTransaction->getConvertedValue('EUR'), 0.01);
        self::assertEquals(2, $newTransaction->getConvertedValue('USD'));
        self::assertEquals(500, $newTransaction->getConvertedValue('HUF'));
        self::assertEqualsWithDelta(
            0.00016666666666666666,
            $newTransaction->getConvertedValue('BTC'),
            0.0000000000000001
        );
    }

    public function testUpdateIncomeAccountUpdatesAccountsBalancesAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $endAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_CASH_EUR_ID);

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(5429.94, $endAccount->getBalance(), 0.01);
        self::assertEquals(552, $endAccount->getTransactionsCount());

        $transaction = $this->createIncome(
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
        self::assertEqualsWithDelta(5529.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateIncomeAccountAndAmountUpdatesAccountBalancesAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $endAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_CASH_EUR_ID);

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(5429.94, $endAccount->getBalance(), 0.01);
        self::assertEquals(552, $endAccount->getTransactionsCount());

        $transaction = $this->createIncome(
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
        self::assertEqualsWithDelta(5479.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateIncomeExecutedAtDoesNotChangeAccountBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(1))->method('convert');

        $executionDate = Carbon::now();

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);

        $transaction = $this->createIncome(
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

        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);

        $transaction->setExecutedAt($executionDate->subMonth());
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals($convertedValues, $transaction->getConvertedValues());
    }

    public function testDeleteIncomeUpdatesAccountBalance(): void
    {
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(16, $this->testCategory->getTransactionsCount(false));

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();

        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(17, $this->testCategory->getTransactionsCount(false));

        $this->em->remove($transaction);
        $this->em->flush();
        $this->em->refresh($this->testAccount);

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(16, $this->testCategory->getTransactionsCount(false));
    }

    private function createIncome($amount, $account, $category, $executedAt, $note): Income
    {
        $income = new Income();
        $income
            ->setAccount($account)
            ->setAmount($amount)
            ->setExecutedAt($executedAt)
            ->setCategory($category)
            ->setOwner($account->getOwner())
            ->setNote($note);

        return $income;
    }
}
