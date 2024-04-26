<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Tests\BaseApiTestCase;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * @group smoke
 */
final class IncomeCRUDTest extends BaseApiTestCase
{
    private const TRANSACTION_URL = '/api/transactions';
    private const INCOME_URL = '/api/transactions/income';

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

        $response = $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => $executionDate->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->testAccount->getId(),
                'note' => 'Test transaction',
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(17, $this->testCategory->getTransactionsCount(false));

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Income::class)->find($content['id']);
        self::assertInstanceOf(Income::class, $transaction);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($this->testAccount, $transaction->getAccount());
        self::assertEquals($this->testCategory, $transaction->getCategory());
        self::assertEquals($this->testAccount->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));
    }

    public function testCreateIncomeSavedWithConvertedValues(): void
    {
        $response = $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '100.0',
                'executedAt' => Carbon::now()->toIso8601String(),
                'category' => (string)$this->testCategory->getId(),
                'account' => (string)$this->testAccount->getId(),
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

    public function testUpdateIncomeAmountUpdatesAccountAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);
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
        $transaction = $this->em->getRepository(Income::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals(50, $transaction->getAmount());
        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11328.35, $this->testAccount->getBalance(), 0.01);
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

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->testAccount->getCurrency()));
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
        $transaction = $this->em->getRepository(Income::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
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

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEquals(100, $transaction->getConvertedValue($this->testAccount->getCurrency()));
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
        $transaction = $this->em->getRepository(Income::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals('Updated transaction note', $transaction->getNote());
        self::assertEquals(50, $transaction->getConvertedValue($endAccount->getCurrency()));
        self::assertEquals(1500, $transaction->getConvertedValue('UAH'));
        self::assertEquals(60, $transaction->getConvertedValue('USD'));

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEquals(553, $endAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEqualsWithDelta(5479.94, $endAccount->getBalance(), 0.01);
    }

    public function testUpdateIncomeExecutedAtDoesNotChangeAccountBalance(): void
    {
        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $executionDate = Carbon::now();

        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(5516, $this->testAccount->getTransactionsCount());

        $transaction = $this->createIncome(
            amount: 100,
            account: $this->testAccount,
            category: $this->testCategory,
            executedAt: $executionDate,
            note: 'Test transaction'
        );

        $convertedValues = $transaction->getConvertedValues();

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);

        $response = $this->client->request('PUT', self::TRANSACTION_URL.'/'.$transaction->getId(), [
            'json' => [
                'executedAt' => $executionDate->subMonth()->toIso8601String(),
                'note' => 'Updated transaction note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        $transaction = $this->em->getRepository(Income::class)->find($content['id']);
        self::assertNotNull($transaction);

        self::assertEquals(5517, $this->testAccount->getTransactionsCount());
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

        $transactionId = $transaction->getId();

        self::assertEqualsWithDelta(11378.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(17, $this->testCategory->getTransactionsCount(false));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$transactionId);
        self::assertResponseIsSuccessful();

        $transaction = $this->em->getRepository(Income::class)->find($transactionId);
        self::assertNull($transaction);

        self::assertEquals(5516, $this->testAccount->getTransactionsCount());
        self::assertEqualsWithDelta(11278.35, $this->testAccount->getBalance(), 0.01);
        self::assertEquals(16, $this->testCategory->getTransactionsCount(false));
    }
}
