<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Service\FixerService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group smoke
 */
final class TransactionCRUDTest extends KernelTestCase
{
    private const TEST_ACCOUNT_ID = 10;
    private const EXCHANGE_RATES = [
        'USD' => 1.2,
        'EUR' => 1.0,
        'HUF' => 300.0,
        'UAH' => 30.0,
        'BTC' => 0.0001,
    ];

    private EntityManagerInterface $em;

    private $mockFixerService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->em = $kernel->getContainer()->get('doctrine')->getManager();
        $this->createFixerServiceMock();
    }

    /**
     * @throws ORMException
     */
    public function testCreateSimpleExpense(): void
    {
        $account = $this->em->getRepository(Account::class)->find(self::TEST_ACCOUNT_ID);
        $category = $this->em->getRepository(ExpenseCategory::class)->findOneByName(Category::CATEGORY_GROCERIES);
        $executionDate = Carbon::now()->startOfDay();

        self::assertEqualsWithDelta(11278.35, $account->getBalance(), 0.01);
        self::assertEquals(5516, $account->getTransactionsCount());

        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $transaction = $this->createExpense(
            amount: 100,
            account: $account,
            category: $category,
            executedAt: $executionDate,
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($account);

        self::assertEquals(5517, $account->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $account->getBalance(), 0.01);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($account, $transaction->getAccount());
        self::assertEquals($category, $transaction->getCategory());
        self::assertEquals($account->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));

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

    public function testUpdateExpenseAmountUpdatesAccountBalance(): void
    {
        $account = $this->em->getRepository(Account::class)->find(self::TEST_ACCOUNT_ID);
        $category = $this->em->getRepository(ExpenseCategory::class)->findOneByName('Groceries');

        self::assertEqualsWithDelta(11278.35, $account->getBalance(), 0.01);
        self::assertEquals(5516, $account->getTransactionsCount());

        $this->mockFixerService->expects(self::exactly(4))->method('convert');

        $transaction = $this->createExpense(
            amount: 100,
            account: $account,
            category: $category,
            executedAt: Carbon::now(),
            note: 'Test transaction'
        );

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($account);

        self::assertEquals(5517, $account->getTransactionsCount());
        self::assertEqualsWithDelta(11178.35, $account->getBalance(), 0.01);

        $transaction->setAmount(50);
        $this->em->flush();
        $this->em->refresh($account);

        self::assertEquals(5517, $account->getTransactionsCount());
        self::assertEqualsWithDelta(11228.35, $account->getBalance(), 0.01);
        self::assertEquals($transaction->getAmount(), $transaction->getConvertedValue('UAH'));
    }

    public function testUpdateExpenseAccountUpdatesAccountsBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testUpdateExpenseExecutedAtDoesNoChangeToAccountBalance(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteExpenseUpdatesAccountBalance(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testCreateSimpleIncome(): void
    {
        $account = $this->em->getRepository(Account::class)->find(self::TEST_ACCOUNT_ID);
        $category = $this->em->getRepository(IncomeCategory::class)->findOneByName('Salary');
        $executionDate = Carbon::now()->startOfDay();

        self::assertEqualsWithDelta(11278.35, $account->getBalance(), 0.01);
        self::assertEquals(5516, $account->getTransactionsCount());

        $this->mockFixerService->expects(self::exactly(2))->method('convert');

        $transaction = new Income();
        $transaction
            ->setAccount($account)
            ->setAmount(100)
            ->setExecutedAt($executionDate)
            ->setCategory($category)
            ->setOwner($account->getOwner())
            ->setNote('Test transaction');

        $this->em->persist($transaction);
        $this->em->flush();
        $this->em->refresh($account);

        self::assertEquals(5517, $account->getTransactionsCount());
        self::assertEqualsWithDelta(11378.35, $account->getBalance(), 0.01);

        self::assertEquals($transaction->getNote(), 'Test transaction');
        self::assertEquals(100, $transaction->getAmount());
        self::assertEquals($account, $transaction->getAccount());
        self::assertEquals($category, $transaction->getCategory());
        self::assertEquals($account->getOwner(), $transaction->getOwner());
        self::assertTrue($executionDate->eq($transaction->getExecutedAt()));

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

    public function testUpdateIncomeAmountUpdatesAccountBalance(): void
    {
        $account = $this->em->getRepository(Account::class)->find(self::TEST_ACCOUNT_ID);
        $category = $this->em->getRepository(IncomeCategory::class)->findOneByName('Salary');

        self::assertEqualsWithDelta(11278.35, $account->getBalance(), 0.01);
        self::assertEquals(5516, $account->getTransactionsCount());

        $this->mockFixerService->expects(self::exactly(4))->method('convert');

        $newTransaction = new Income();
        $newTransaction
            ->setAccount($account)
            ->setAmount(100)
            ->setExecutedAt(Carbon::now())
            ->setCategory($category)
            ->setOwner($account->getOwner())
            ->setNote('Test transaction');

        $this->em->persist($newTransaction);
        $this->em->flush();
        $this->em->refresh($account);

        self::assertEquals(5517, $account->getTransactionsCount());
        self::assertEqualsWithDelta(11378.35, $account->getBalance(), 0.01);

        $newTransaction->setAmount(50);
        $this->em->flush();
        $this->em->refresh($account);

        self::assertEquals(5517, $account->getTransactionsCount());
        self::assertEqualsWithDelta(11328.35, $account->getBalance(), 0.01);
        self::assertEquals($newTransaction->getAmount(), $newTransaction->getConvertedValue('UAH'));
    }

    public function testUpdateIncomeAccountUpdatesAccountsBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteIncomeUpdatesAccountBalance(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteTransferExpenseDeletesRelatedTransferWithTransactionsAndUpdatesAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteTransferIncomeDeletesRelatedTransferWithTransactionsAndUpdatesAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testCreateExpenseWithCompensationsProperlyCalculatesValue(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testAddingCompensationToExpenseRecalculatesValue(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testUpdatingExpenseWithCompensationsAmountRecalculatesValue(): void
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

    public function createIncome($amount, $account, $category, $executedAt, $note): Income
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

    private function createFixerServiceMock(): void
    {
        $mockFixerService = $this->createMock(FixerService::class);

        $mockFixerService
            ->method('convert')
            ->willReturnCallback(
                static function (float $amount, string $fromCurrency, ?CarbonInterface $executionDate = null) {
                    $convertedValues = [];
                    foreach (self::EXCHANGE_RATES as $currency => $rate) {
                        $convertedValues[$currency] = $amount / self::EXCHANGE_RATES[$fromCurrency] * $rate;
                    }

                    return $convertedValues;
                }
            );

        self::getContainer()->set(FixerService::class, $mockFixerService);
        $this->mockFixerService = $mockFixerService;
    }
}
