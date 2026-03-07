<?php

namespace App\Tests\ApiPlatform\Action;

use App\Entity\Account;
use App\Entity\ExchangeRateSnapshot;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Tests\BaseApiTestCase;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class BulkTransactionCreateActionTest extends BaseApiTestCase
{
    private ExpenseCategory $testExpenseCategory;

    private IncomeCategory $testIncomeCategory;

    /**
     * Create a snapshot for a given date (YYYY-MM-DD) if it does not exist yet.
     * This is only to guarantee rates for the happy-path tests that use 2026-02-22.
     */
    private function createExchangeRateSnapshot(string $date): void
    {
        $effectiveAt = new DateTimeImmutable($date.' 00:00:00');

        $repo = $this->em->getRepository(ExchangeRateSnapshot::class);
        $existing = $repo->findOneBy(['effectiveAt' => $effectiveAt]);
        if ($existing instanceof ExchangeRateSnapshot) {
            return;
        }

        $snapshot = new ExchangeRateSnapshot();
        $snapshot->setEffectiveAt($effectiveAt);

        // Minimal consistent dummy rates
        $snapshot->setUsdPerEur('1.10');  // 1 EUR = 1.10 USD
        $snapshot->setHufPerEur('400');   // 1 EUR = 400 HUF
        $snapshot->setUahPerEur('40');    // 1 EUR = 40 UAH

        $this->em->persist($snapshot);
        $this->em->flush();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // All happy-path tests use 2026-02-22 as executedAt
        $this->createExchangeRateSnapshot('2026-02-22');

        $expenseCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Groceries']);
        assert($expenseCategory instanceof ExpenseCategory);
        $this->testExpenseCategory = $expenseCategory;
        $incomeCategory = $this->em->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Compensation']);
        assert($incomeCategory instanceof IncomeCategory);
        $this->testIncomeCategory = $incomeCategory;
    }

    /**
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testUnauthorizedUserCannotBulkCreate(): void
    {
        $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'headers' => ['authorization' => null],
            'json' => [],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateSuccessWithMixedTypes(): void
    {
        $accountIri = $this->iri($this->accountCashEUR);
        $expenseCategoryIri = $this->iri($this->testExpenseCategory);
        $incomeCategoryIri = $this->iri($this->testIncomeCategory);

        $payload = [
            [
                'type' => 'expense',
                'account' => $accountIri,
                'amount' => '123.45',
                'category' => $expenseCategoryIri,
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-test-expense-1',
            ],
            [
                'type' => 'income',
                'account' => $accountIri,
                'amount' => '500.00',
                'category' => $incomeCategoryIri,
                'executedAt' => '2026-02-22T13:25:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-test-income-1',
            ],
        ];

        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => $payload,
        ]);

        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertCount(2, $content);

        self::assertSame('expense', $content[0]['type']);
        self::assertSame('income', $content[1]['type']);

        $transactionRepository = $this->em->getRepository(Transaction::class);

        /** @var Transaction|null $savedExpense */
        $savedExpense = $transactionRepository->findOneBy(['note' => 'bulk-test-expense-1']);
        /** @var Transaction|null $savedIncome */
        $savedIncome = $transactionRepository->findOneBy(['note' => 'bulk-test-income-1']);

        self::assertNotNull($savedExpense);
        self::assertInstanceOf(Expense::class, $savedExpense);
        self::assertEquals(123.45, $savedExpense->getAmount());

        self::assertNotNull($savedIncome);
        self::assertInstanceOf(Income::class, $savedIncome);
        self::assertEquals(500.00, $savedIncome->getAmount());
    }

    /**
     * Payload is an object instead of array: must fail with 400 and clear message.
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateFailsWhenPayloadIsNotArray(): void
    {
        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => [
                'type' => 'expense',
                'account' => $this->iri($this->accountCashEUR),
                'amount' => '100',
                'category' => $this->iri($this->testExpenseCategory),
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $response->toArray(false);

        self::assertArrayHasKey('detail', $content);
        self::assertSame('Validation or conversion failed for one or more items', $content['detail']);
    }

    /**
     * One invalid item should fail the whole bulk and nothing must be persisted.
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateFailsOnInvalidItemAndDoesNotPersistAnything(): void
    {
        $accountIri = $this->iri($this->accountCashEUR);
        $expenseCategoryIri = $this->iri($this->testExpenseCategory);
        $incomeCategoryIri = $this->iri($this->testIncomeCategory);

        $payload = [
            [
                'type' => 'expense',
                'account' => $accountIri,
                'amount' => '100',
                'category' => $expenseCategoryIri,
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-ok-should-not-persist-on-error',
            ],
            [
                'type' => 'income',
                'account' => $accountIri,
                'amount' => '-50',
                'category' => $incomeCategoryIri,
                'executedAt' => '2026-02-22T13:25:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-invalid-amount',
            ],
        ];

        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => $payload,
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $response->toArray(false);

        self::assertArrayHasKey('detail', $content);
        self::assertSame('Validation or conversion failed for one or more items', $content['detail']);
        self::assertArrayHasKey('errors', $content);
        self::assertArrayHasKey('1', $content['errors']);

        $transactionRepository = $this->em->getRepository(Transaction::class);

        $okTransaction = $transactionRepository->findOneBy(['note' => 'bulk-ok-should-not-persist-on-error']);
        $badTransaction = $transactionRepository->findOneBy(['note' => 'bulk-invalid-amount']);

        self::assertNull($okTransaction);
        self::assertNull($badTransaction);
    }

    /**
     * An empty array payload should fail with a clear message.
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateFailsOnEmptyArrayPayload(): void
    {
        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => [],
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $response->toArray(false);

        self::assertArrayHasKey('detail', $content);
        self::assertSame('No valid items to persist', $content['detail']);
    }

    /**
     * Unsupported transaction type must result in an error and nothing persisted.
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateFailsOnUnsupportedTransactionType(): void
    {
        $payload = [
            [
                'type' => 'unsupported',
                'account' => $this->iri($this->accountCashEUR),
                'amount' => '100',
                'category' => $this->iri($this->testExpenseCategory),
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-unsupported-type',
            ],
        ];

        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => $payload,
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $response->toArray(false);

        self::assertArrayHasKey('detail', $content);
        self::assertSame('Validation or conversion failed for one or more items', $content['detail']);
        self::assertArrayHasKey('errors', $content);
        self::assertArrayHasKey('0', $content['errors']);

        $firstItemErrors = $content['errors']['0'];
        self::assertIsArray($firstItemErrors);
        self::assertNotEmpty($firstItemErrors);

        $firstMessage = (string)$firstItemErrors[0];
        self::assertStringContainsStringIgnoringCase('type', $firstMessage);
    }

    /**
     * Expense with embedded compensations should persist expense and linked income(s).
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateWithCompensationsPersistsExpenseAndLinkedIncomes(): void
    {
        $accountIri = $this->iri($this->accountCashEUR);
        $expenseNote = 'bulk-expense-with-compensation';
        $compensationNote = 'bulk-compensation-income';

        $payload = [
            [
                'type' => 'expense',
                'account' => $accountIri,
                'amount' => '100',
                'category' => $this->iri($this->testExpenseCategory),
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => $expenseNote,
                'compensations' => [
                    [
                        'type' => 'income',
                        'account' => $accountIri,
                        'amount' => '50',
                        'category' => $this->iri($this->testIncomeCategory),
                        'executedAt' => '2026-02-22T13:25:00.000Z',
                        'isDraft' => false,
                        'note' => $compensationNote,
                    ],
                ],
            ],
        ];

        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => $payload,
        ]);

        self::assertResponseIsSuccessful();

        $transactionRepository = $this->em->getRepository(Transaction::class);

        /** @var Expense|null $expense */
        $expense = $transactionRepository->findOneBy(['note' => $expenseNote]);
        self::assertNotNull($expense);
        self::assertInstanceOf(Expense::class, $expense);
        self::assertEquals(100.0, $expense->getAmount());

        self::assertTrue($expense->hasCompensations());
        $compensations = $expense->getCompensations();
        self::assertCount(1, $compensations);

        /** @var Income $compensation */
        $compensation = $compensations->first();
        self::assertInstanceOf(Income::class, $compensation);
        self::assertEquals(50.0, $compensation->getAmount());
        self::assertSame($compensationNote, $compensation->getNote());
        self::assertSame($expense, $compensation->getOriginalExpense());
    }

    /**
     * Bulk create must update account balance and set convertedValues on transactions.
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateUpdatesAccountBalanceAndSetsConvertedValues(): void
    {
        $accountId = $this->accountCashEUR->getId();
        $accountIri = $this->iri($this->accountCashEUR);
        $balanceBefore = $this->accountCashEUR->getBalance();

        $incomeNote = 'bulk-balance-check-income';
        $expenseNote = 'bulk-balance-check-expense';

        $payload = [
            [
                'type' => 'income',
                'account' => $accountIri,
                'amount' => '500',
                'category' => $this->iri($this->testIncomeCategory),
                'executedAt' => '2026-02-22T13:25:00.000Z',
                'isDraft' => false,
                'note' => $incomeNote,
            ],
            [
                'type' => 'expense',
                'account' => $accountIri,
                'amount' => '100',
                'category' => $this->iri($this->testExpenseCategory),
                'executedAt' => '2026-02-22T13:26:00.000Z',
                'isDraft' => false,
                'note' => $expenseNote,
            ],
        ];

        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => $payload,
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();

        /** @var Account|null $accountAfter */
        $accountAfter = $this->em->getRepository(Account::class)->find($accountId);
        self::assertNotNull($accountAfter, 'Account must exist after bulk creation.');
        $balanceAfter = $accountAfter->getBalance();

        self::assertEqualsWithDelta($balanceBefore + 400.0, $balanceAfter, 0.01);

        $transactionRepository = $this->em->getRepository(Transaction::class);

        /** @var Transaction|null $income */
        $income = $transactionRepository->findOneBy(['note' => $incomeNote]);
        self::assertNotNull($income);

        // Check internal convertedValues on the persisted entity
        $reflection = new \ReflectionClass($income);
        $property = $reflection->getProperty('convertedValues');
        $property->setAccessible(true);
        $convertedValues = $property->getValue($income);

        self::assertIsArray($convertedValues);
        self::assertNotEmpty($convertedValues);
    }

    /**
     * If no snapshot exists for a past date (before the earliest snapshot), conversion must fail and nothing is persisted.
     *
     * @group transactions
     * @group bulk
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBulkCreateFailsWhenNoExchangeRateSnapshotForPastDate(): void
    {
        $note = 'bulk-no-rates-should-fail';

        $payload = [
            [
                'type' => 'expense',
                'account' => $this->iri($this->accountCashEUR),
                'amount' => '100',
                'category' => $this->iri($this->testExpenseCategory),
                // date intentionally before any reasonable baseline (e.g. fixtures start at 1991-01-01)
                'executedAt' => '1980-01-01T10:00:00.000Z',
                'isDraft' => false,
                'note' => $note,
            ],
        ];

        $response = $this->client->request('POST', self::TRANSACTION_BULK_CREATE_URL, [
            'json' => $payload,
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $response->toArray(false);

        self::assertArrayHasKey('detail', $content);
        self::assertSame('Validation or conversion failed for one or more items', $content['detail']);
        self::assertArrayHasKey('errors', $content);
        self::assertArrayHasKey('0', $content['errors']);

        $firstErrors = $content['errors']['0'];
        self::assertIsArray($firstErrors);
        self::assertNotEmpty($firstErrors);

        $message = (string)$firstErrors[0];
        self::assertStringContainsString('Failed to resolve exchange rates', $message);
        self::assertStringContainsString('1980-01-01', $message);

        $transactionRepository = $this->em->getRepository(Transaction::class);
        $transaction = $transactionRepository->findOneBy(['note' => $note]);
        self::assertNull($transaction);
    }
}
