<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Debt;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\User;
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
    protected const EXPENSE_URL = '/api/transactions/expense';
    protected const INCOME_URL = '/api/transactions/income';
    protected const DEBT_URL = '/api/debts';

    protected const ACCOUNT_MONO_UAH_ID = 10;
    protected const CATEGORY_GROCERIES = 'Groceries';
    protected const CATEGORY_COMPENSATION = 'Compensation';

    private Account $testAccount;

    private IncomeCategory $debtIncomeCategory;
    private ExpenseCategory $debtExpenseCategory;

    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testUser = $this->em->getRepository(User::class)->findOneById(2);
        $this->testAccount = $this->em->getRepository(Account::class)->find(self::ACCOUNT_MONO_UAH_ID);
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
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
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
                'account' => $this->testAccount->getId(),
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

    public function testAddIncomeToDebtUpdatedBalanceAndConvertedValues(): void
    {
        $this->mockFixerService->expects(self::exactly(4))->method('convert');
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
                'account' => $this->testAccount->getId(),
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

    public function testRemoveExpenseUpdatesBalanceAndConvertedValues(): void
    {
        self::markTestIncomplete('Implement this test');
    }

    public function testRemoveIncomeUpdatesBalanceAndConvertedValues(): void
    {
        self::markTestIncomplete('Implement this test');
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
        foreach($content as $debt) {
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
        self::markTestIncomplete('Implement this test');
    }

    public function testFetchListOfAllDebts(): void
    {
        $response = $this->client->request('GET', '/api/v2/debt?withClosed=1');
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(65, $content);
        foreach($content as $debt) {
            self::assertArrayHasKey('id', $debt);
            self::assertArrayHasKey('debtor', $debt);
            self::assertArrayHasKey('balance', $debt);
            self::assertArrayHasKey('currency', $debt);
            self::assertArrayHasKey('note', $debt);
            self::assertArrayHasKey('createdAt', $debt);
            self::assertArrayHasKey('closedAt', $debt);
        }
    }

    private function createDebt(string $debtor, float $initialBalance, string $currency, string $note, ?CarbonImmutable $createdAt = null): Debt
    {
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
