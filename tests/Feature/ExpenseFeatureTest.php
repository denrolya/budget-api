<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Tests\BaseApiTestCase;
use Carbon\Carbon;

/**
 * @group smoke
 */
final class ExpenseFeatureTest extends BaseApiTestCase
{
    private ExpenseCategory $testCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testCategory = $this->em->getRepository(ExpenseCategory::class)->findOneByName(
            self::CATEGORY_EXPENSE_GROCERIES
        );
    }

    public function testCreateExpenseUpdatesAccountAndCategory(): void
    {
        $executionDate = Carbon::now()->startOfDay();

        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $executionDate->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->accountMonoUAH->getId(),
                'note' => 'Test transaction',
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(465, $this->testCategory->getTransactionsCount(false));

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertInstanceOf(Expense::class, $transaction);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->accountMonoUAH, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->accountMonoUAH->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));
    }

    public function testCreateExpenseSavedWithConvertedValues(): void
    {
        $this->mockFixerService->expects(self::once())->method('convert');

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->accountMonoUAH->getId(),
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

    public function testUpdateExpenseAmountUpdatesAccountAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountMonoUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals($transaction->getAmount(), $transaction->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(3.33, $transaction->getConvertedValue('EUR'), 0.01);
        self::assertEquals(4, $transaction->getConvertedValue('USD'));
        self::assertEquals(1000, $transaction->getConvertedValue('HUF'));
        self::assertEqualsWithDelta(
            0.0003333333333333333,
            $transaction->getConvertedValue('BTC'),
            0.0000000000000001
        );

        $response = $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transaction->getId(), [
            'json' => [
                'amount' => '50',
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals(50, $transaction->getAmount());
        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(11228.35, $this->accountMonoUAH->getBalance(), 0.01);
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

    public function testUpdateExpenseAccountUpdatesAccountsBalances(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $endAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_CASH_EUR_ID);

        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(5429.94, $endAccount->getBalance(), 0.01);
        self::assertEquals(552, $endAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountMonoUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->accountMonoUAH->getCurrency()));
        self::assertEquals(4, $transaction->getConvertedValue('USD'));

        $response = $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transaction->getId(), [
            'json' => [
                'account' => (string)$endAccount->getId(),
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(100, $transaction->getConvertedValue($endAccount->getCurrency()));
        self::assertEquals(3000, $transaction->getConvertedValue('UAH'));
        self::assertEquals(120, $transaction->getConvertedValue('USD'));

        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEquals(553, $endAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEqualsWithDelta(5329.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateExpenseAccountAndAmountUpdatesAccountBalancesAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $endAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_CASH_EUR_ID);

        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(5429.94, $endAccount->getBalance(), 0.01);
        self::assertEquals(552, $endAccount->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountMonoUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->accountMonoUAH->getCurrency()));
        self::assertEquals(4, $transaction->getConvertedValue('USD'));

        $response = $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transaction->getId(), [
            'json' => [
                'account' => (string)$endAccount->getId(),
                'amount' => '50',
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(50, $transaction->getConvertedValue($endAccount->getCurrency()));
        self::assertEquals(1500, $transaction->getConvertedValue('UAH'));
        self::assertEquals(60, $transaction->getConvertedValue('USD'));

        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEquals(553, $endAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEqualsWithDelta(5379.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateExpenseExecutedAtDoesNotChangeAccountBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $executionDate = Carbon::now();

        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountMonoUAH,
            category: $this->testCategory,
            executedAt: $executionDate,
            note: 'Test transaction'
        );

        $convertedValues = $transaction->getConvertedValues();

        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->accountMonoUAH->getBalance(), 0.01);

        $response = $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transaction->getId(), [
            'json' => [
                'executedAt' => $executionDate->subMonth()->toIso8601String(),
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Expense::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals(5517, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals($convertedValues, $transaction->getConvertedValues());
    }

    public function testDeleteExpenseUpdatesAccountAndCategory(): void
    {
        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));

        $transaction = $this->createExpense(
            amount: 100,
            account: $this->accountMonoUAH,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $transactionId = $transaction->getId();

        self::assertEqualsWithDelta(11178.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(465, $this->testCategory->getTransactionsCount(false));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$transactionId);
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Expense::class)->find($transactionId);
        self::assertNull($transaction);

        self::assertEquals(5516, $this->accountMonoUAH->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->accountMonoUAH->getBalance(), 0.01);
        self::assertEquals(464, $this->testCategory->getTransactionsCount(false));
    }
}
