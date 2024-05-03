<?php

namespace App\Tests\Feature;

use App\Entity\Category;
use App\Entity\Debt;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Tests\BaseApiTestCase;
use Carbon\CarbonImmutable;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * @group smoke
 */
final class DebtFeatureTest extends BaseApiTestCase
{
    private IncomeCategory $debtIncomeCategory;

    private ExpenseCategory $debtExpenseCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debtIncomeCategory = $this->em->getRepository(IncomeCategory::class)->findOneByName(
            Category::CATEGORY_DEBT
        );
        $this->debtExpenseCategory = $this->em->getRepository(ExpenseCategory::class)->findOneByName(
            Category::CATEGORY_DEBT
        );
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws \Exception
     */
    public function testCreateDebt(): void
    {
        $this->mockFixerService->expects(self::exactly(1))->method('convert');

        $createdAt = CarbonImmutable::now();
        $response = $this->client->request('POST', self::DEBT_URL, [
            'json' => [
                'balance' => '0',
                'debtor' => 'Test Debtor',
                'note' => 'Test debt',
                'currency' => 'EUR',
                'createdAt' => $createdAt->toIso8601String(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        self::assertArraySubset([
            'balance' => '0',
            'debtor' => 'Test Debtor',
            'note' => 'Test debt',
            'currency' => 'EUR',
            'createdAt' => $createdAt->toIso8601String(),
        ], $content);
        self::assertArrayHasKey('id', $content);

        $debt = $this->em->getRepository(Debt::class)->find($content['id']);

        self::assertInstanceOf(Debt::class, $debt);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testAddExpenseToDebtUpdatedBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(3))->method('convert');
        self::assertEquals(112, $this->debtExpenseCategory->getTransactionsCount());

        $debt = $this->createDebt(
            debtor: 'Test Debtor',
            initialBalance: 0,
            currency: 'UAH',
            note: 'Test debt'
        );

        self::assertNotNull($debt->getId());

        $response = $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '10',
                'note' => 'Test expense',
                'debt' => $debt->getId(),
                'executedAt' => CarbonImmutable::now()->toIso8601String(),
                'account' => $this->accountMonoUAH->getId(),
                'category' => $this->debtExpenseCategory->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(0.33, $debt->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.4, $debt->getConvertedValue('USD'), 0.01);
    }

    public function testUpdateExpenseAmountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
        self::assertEquals(112, $this->debtExpenseCategory->getTransactionsCount());

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountMonoUAH,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$expense->getId(), [
            'json' => [
                'amount' => '20'
            ]
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(20, $debt->getBalance());
        self::assertEquals(20, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateExpenseAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
        self::assertEquals(112, $this->debtExpenseCategory->getTransactionsCount());

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountCashEUR,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(300, $debt->getBalance());
        self::assertEquals(300, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$expense->getId(), [
            'json' => [
                'account' => $this->accountCashUAH->getId(),
            ]
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateExpenseAmountAndAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
        self::assertEquals(112, $this->debtExpenseCategory->getTransactionsCount());

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$expense->getId(), [
            'json' => [
                'amount' => '20',
                'account' => $this->accountCashEUR->getId(),
            ]
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(600, $debt->getBalance());
        self::assertEquals(600, $debt->getConvertedValue('UAH'));
    }

    public function testRemoveExpenseUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(3))->method('convert');
        self::assertEquals(112, $this->debtExpenseCategory->getTransactionsCount());

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountMonoUAH,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertEquals(113, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$expense->getId());
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(112, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(0, $debt->getBalance());
        self::assertEquals(0, $debt->getConvertedValue('UAH'));
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testAddIncomeToDebtUpdatedBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(3))->method('convert');
        self::assertEquals(82, $this->debtIncomeCategory->getTransactionsCount());

        $debt = $this->createDebt(
            debtor: 'Test Debtor',
            initialBalance: 0,
            currency: 'UAH',
            note: 'Test debt'
        );

        self::assertNotNull($debt->getId());

        $response = $this->client->request('POST', self::INCOME_URL, [
            'json' => [
                'amount' => '10',
                'note' => 'Test income',
                'debt' => $debt->getId(),
                'executedAt' => CarbonImmutable::now()->toIso8601String(),
                'account' => $this->accountMonoUAH->getId(),
                'category' => $this->debtIncomeCategory->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(-0.33, $debt->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(-0.4, $debt->getConvertedValue('USD'), 0.01);
    }

    public function testUpdateIncomeAmountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
        self::assertEquals(82, $this->debtIncomeCategory->getTransactionsCount());

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountMonoUAH,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$income->getId(), [
            'json' => [
                'amount' => '20'
            ]
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-20, $debt->getBalance());
        self::assertEquals(-20, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateIncomeAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
        self::assertEquals(82, $this->debtIncomeCategory->getTransactionsCount());

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountCashEUR,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-300, $debt->getBalance());
        self::assertEquals(-300, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$income->getId(), [
            'json' => [
                'account' => $this->accountCashUAH->getId(),
            ]
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateIncomeAmountAndAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
        self::assertEquals(82, $this->debtIncomeCategory->getTransactionsCount());

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        // TODO: Get rid of this refresh. It has smth to do with how $transactions property is initialized in Debt entity.
        $this->em->refresh($debt);

        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$income->getId(), [
            'json' => [
                'amount' => '20',
                'account' => $this->accountCashEUR->getId(),
            ]
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-600, $debt->getBalance());
        self::assertEquals(-600, $debt->getConvertedValue('UAH'));
    }

    public function testRemoveIncomeUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(3))->method('convert');
        self::assertEquals(82, $this->debtIncomeCategory->getTransactionsCount());

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountMonoUAH,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        $this->em->refresh($debt);

        self::assertEquals(83, $this->debtIncomeCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$income->getId());
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals(82, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(0, $debt->getBalance());
        self::assertEquals(0, $debt->getConvertedValue('UAH'));
    }

    public function testCloseDebtShouldDoWhat(): void
    {
        self::markTestIncomplete('Implement this test');
    }

    public function testFetchListOfOpenedDebts(): void
    {
        $response = $this->client->request('GET', '/api/v2/debt');
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(6, $content);
        foreach ($content as $debt) {
            self::assertArrayHasKey('id', $debt);
            self::assertArrayHasKey('debtor', $debt);
            self::assertArrayHasKey('balance', $debt);
            self::assertArrayHasKey('currency', $debt);
            self::assertArrayHasKey('note', $debt);
            self::assertArrayHasKey('createdAt', $debt);
            self::assertArrayHasKey('closedAt', $debt);
            self::assertNull($debt['closedAt']);
        }
    }

    public function testFetchListOfClosedDebts(): void
    {
        self::markTestIncomplete('Functionality is not implemented yet.');
    }

    public function testFetchListOfAllDebts(): void
    {
        $response = $this->client->request('GET', '/api/v2/debt?withClosed=1');
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(65, $content);
        foreach ($content as $debt) {
            self::assertArrayHasKey('id', $debt);
            self::assertArrayHasKey('debtor', $debt);
            self::assertArrayHasKey('balance', $debt);
            self::assertArrayHasKey('currency', $debt);
            self::assertArrayHasKey('note', $debt);
            self::assertArrayHasKey('createdAt', $debt);
            self::assertArrayHasKey('closedAt', $debt);
        }
    }

    private function createDebt(
        string $debtor,
        float $initialBalance,
        string $currency,
        string $note,
        ?CarbonImmutable $createdAt = null
    ): Debt {
        $debt = (new Debt())
            ->setDebtor($debtor)
            ->setBalance($initialBalance)
            ->setCurrency($currency)
            ->setNote($note)
            ->setCreatedAt($createdAt ?? CarbonImmutable::now())
            ->setOwner($this->testUser);

        $this->em->persist($debt);
        $this->em->flush();

        return $debt;
    }
}
