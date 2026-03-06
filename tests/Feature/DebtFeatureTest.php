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
    protected bool $useAssetsManagerMock = true;

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
        $this->mockAssetsManager->expects(self::once())->method('convert');

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
        $this->mockAssetsManager->expects(self::exactly(3))->method('convert');
        $countBefore = $this->debtExpenseCategory->getTransactionsCount();

        $debt = $this->createDebt(
            debtor: 'Test Debtor',
            initialBalance: 0,
            currency: 'UAH',
            note: 'Test debt'
        );

        self::assertNotNull($debt->getId());

        $this->client->request('POST', self::EXPENSE_URL, [
            'json' => [
                'amount' => '10',
                'note' => 'Test expense',
                'debt' => $debt->getId(),
                'executedAt' => CarbonImmutable::now()->toIso8601String(),
                'account' => $this->accountCashUAH->getId(),
                'category' => $this->debtExpenseCategory->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(0.33, $debt->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(0.4, $debt->getConvertedValue('USD'), 0.01);
    }

    public function testUpdateExpenseAmountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $countBefore = $this->debtExpenseCategory->getTransactionsCount();

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$expense->getId(), [
            'json' => [
                'amount' => '20',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(20, $debt->getBalance());
        self::assertEquals(20, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateExpenseAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $countBefore = $this->debtExpenseCategory->getTransactionsCount();

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountCashEUR,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(300, $debt->getBalance());
        self::assertEquals(300, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$expense->getId(), [
            'json' => [
                'account' => $this->accountCashUAH->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateExpenseAmountAndAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $countBefore = $this->debtExpenseCategory->getTransactionsCount();

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
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
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(600, $debt->getBalance());
        self::assertEquals(600, $debt->getConvertedValue('UAH'));
    }

    public function testRemoveExpenseUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(3))->method('convert');
        $countBefore = $this->debtExpenseCategory->getTransactionsCount();

        $expense = $this->createExpense(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtExpenseCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test expense',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $expense->getDebt();

        self::assertEquals($countBefore + 1, $this->debtExpenseCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($expense->getId());
        self::assertEquals($expense->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(10, $debt->getBalance());
        self::assertEquals(10, $debt->getConvertedValue('UAH'));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$expense->getId());
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore, $this->debtExpenseCategory->getTransactionsCount());
        self::assertEquals(0, $debt->getBalance());
        self::assertEquals(0, $debt->getConvertedValue('UAH'));
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testAddIncomeToDebtUpdatedBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(3))->method('convert');
        $countBefore = $this->debtIncomeCategory->getTransactionsCount();

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
                'account' => $this->accountCashUAH->getId(),
                'category' => $this->debtIncomeCategory->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));
        self::assertEqualsWithDelta(-0.33, $debt->getConvertedValue('EUR'), 0.01);
        self::assertEqualsWithDelta(-0.4, $debt->getConvertedValue('USD'), 0.01);
    }

    public function testUpdateIncomeAmountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $countBefore = $this->debtIncomeCategory->getTransactionsCount();

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$income->getId(), [
            'json' => [
                'amount' => '20',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-20, $debt->getBalance());
        self::assertEquals(-20, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateIncomeAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $countBefore = $this->debtIncomeCategory->getTransactionsCount();

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountCashEUR,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-300, $debt->getBalance());
        self::assertEquals(-300, $debt->getConvertedValue('UAH'));

        $this->client->request('PUT', self::TRANSACTION_URL.'/'.$income->getId(), [
            'json' => [
                'account' => $this->accountCashUAH->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));
    }

    public function testUpdateIncomeAmountAndAccountUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(4))->method('convert');
        $countBefore = $this->debtIncomeCategory->getTransactionsCount();

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

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
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(-600, $debt->getBalance());
        self::assertEquals(-600, $debt->getConvertedValue('UAH'));
    }

    public function testRemoveIncomeUpdatesBalanceAndConvertedValues(): void
    {
        $this->mockAssetsManager->expects(self::exactly(3))->method('convert');
        $countBefore = $this->debtIncomeCategory->getTransactionsCount();

        $income = $this->createIncome(
            amount: 10,
            account: $this->accountCashUAH,
            category: $this->debtIncomeCategory,
            executedAt: CarbonImmutable::now(),
            note: 'Test income',
            debt: (new Debt())->setDebtor('Test Debtor')->setCurrency('UAH')->setOwner($this->testUser)
        );

        $debt = $income->getDebt();

        self::assertEquals($countBefore + 1, $this->debtIncomeCategory->getTransactionsCount());
        self::assertNotNull($debt->getId());
        self::assertNotNull($income->getId());
        self::assertEquals($income->getDebt(), $debt);
        self::assertEquals(1, $debt->getTransactionsCount());
        self::assertEquals(-10, $debt->getBalance());
        self::assertEquals(-10, $debt->getConvertedValue('UAH'));

        $this->client->request('DELETE', self::TRANSACTION_URL.'/'.$income->getId());
        self::assertResponseIsSuccessful();

        $this->em->refresh($debt);

        self::assertEquals($countBefore, $this->debtIncomeCategory->getTransactionsCount());
        self::assertEquals(0, $debt->getBalance());
        self::assertEquals(0, $debt->getConvertedValue('UAH'));
    }

    /**
     * Closing a debt via the DELETE endpoint performs a soft-delete using Gedmo
     * SoftDeleteable: it sets the `closedAt` timestamp instead of removing the
     * record. The closed debt must no longer appear in the default (open-only)
     * list, but the underlying database row still exists.
     */
    public function testCloseDebtSetsClosedAtAndHidesFromOpenList(): void
    {
        $debt = $this->createDebt(
            debtor: 'Borrower',
            initialBalance: 0,
            currency: 'EUR',
            note: 'Debt to close'
        );

        $debtId = $debt->getId();

        // Clear the identity map so the GET request loads entities fresh from DB
        // (avoids JMS serialization issues with CarbonImmutable vs DateTime)
        $this->em->clear();

        // Debt appears in the open list before closing
        $openBefore = $this->client->request('GET', '/api/v2/debt');
        self::assertResponseIsSuccessful();
        $openIds = array_column($openBefore->toArray(), 'id');
        self::assertContains($debtId, $openIds);

        // Close the debt
        $this->client->request('DELETE', self::DEBT_URL.'/'.$debtId);
        self::assertResponseIsSuccessful();

        // The entity still exists in the database but has closedAt set.
        // We must disable the softdeleteable filter to find soft-deleted records.
        $this->em->clear();
        $this->em->getFilters()->disable('softdeleteable');
        $closedDebt = $this->em->getRepository(Debt::class)->find($debtId);
        $this->em->getFilters()->enable('softdeleteable');
        self::assertNotNull($closedDebt, 'Debt record must not be hard-deleted');
        self::assertNotNull($closedDebt->getClosedAt(), 'closedAt must be set after closing');

        // The closed debt no longer appears in the default open list
        $openAfter = $this->client->request('GET', '/api/v2/debt');
        self::assertResponseIsSuccessful();
        $openIdsAfter = array_column($openAfter->toArray(), 'id');
        self::assertNotContains($debtId, $openIdsAfter);
    }

    public function testFetchListOfOpenedDebts(): void
    {
        $response = $this->client->request('GET', '/api/v2/debt');
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertNotEmpty($content);
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

    /**
     * Closed debts are excluded from the default list (GET /api/v2/debt) but
     * are included when the `withClosed=1` query parameter is passed. This
     * verifies that the filtering is working and that closed records are still
     * accessible when explicitly requested.
     */
    public function testFetchListOfClosedDebts(): void
    {
        $debt = $this->createDebt(
            debtor: 'Closed Borrower',
            initialBalance: 0,
            currency: 'EUR',
            note: 'Debt that will be closed'
        );

        $debtId = $debt->getId();

        // Close the debt
        $this->client->request('DELETE', self::DEBT_URL.'/'.$debtId);
        self::assertResponseIsSuccessful();

        // Clear the identity map so GET requests load entities fresh from DB
        // (avoids JMS serialization issues with CarbonImmutable vs DateTime)
        $this->em->clear();

        // Default list (open only) must NOT contain the closed debt
        $openList = $this->client->request('GET', '/api/v2/debt');
        self::assertResponseIsSuccessful();
        $openIds = array_column($openList->toArray(), 'id');
        self::assertNotContains($debtId, $openIds, 'Closed debt must not appear in the open list');

        // List with withClosed=1 MUST contain the closed debt
        $allList = $this->client->request('GET', '/api/v2/debt?withClosed=1');
        self::assertResponseIsSuccessful();
        $allIds = array_column($allList->toArray(), 'id');
        self::assertContains($debtId, $allIds, 'Closed debt must appear when withClosed=1');
    }

    public function testFetchListOfAllDebts(): void
    {
        $response = $this->client->request('GET', '/api/v2/debt?withClosed=1');
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertNotEmpty($content);
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
