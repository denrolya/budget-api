<?php

namespace App\Tests\ApiPlatform\Action;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use App\Tests\BaseApiTestCase;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class BulkTransactionCreateActionTest extends BaseApiTestCase
{
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
        $payload = [
            [
                'type' => 'expense',
                'account' => 25,
                'amount' => '123.45',
                'category' => 21,
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-test-expense-1',
            ],
            [
                'type' => 'income',
                'account' => 25,
                'amount' => '500.00',
                'category' => 137,
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
                'account' => 25,
                'amount' => '100',
                'category' => 21,
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $response->toArray(false);

        self::assertArrayHasKey('detail', $content);
        // Current behavior: non-array payload is treated as invalid items
        self::assertSame('Validation failed for one or more items', $content['detail']);
        self::assertArrayHasKey('errors', $content);
        self::assertIsArray($content['errors']);
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
    public function testBulkCreateFailsOnInvalidItemAndDoesNotPersistAnything(): void
    {
        $payload = [
            [
                'type' => 'expense',
                'account' => 25,
                'amount' => '100',
                'category' => 21,
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => 'bulk-ok-should-not-persist-on-error',
            ],
            [
                'type' => 'income',
                'account' => 25,
                'amount' => '-50',
                'category' => 137,
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

        self::assertArrayHasKey('errors', $content);
        self::assertArrayHasKey('1', $content['errors']);

        $transactionRepository = $this->em->getRepository(Transaction::class);

        $okTransaction = $transactionRepository->findOneBy(['note' => 'bulk-ok-should-not-persist-on-error']);
        $badTransaction = $transactionRepository->findOneBy(['note' => 'bulk-invalid-amount']);

        self::assertNull($okTransaction);
        self::assertNull($badTransaction);
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
                'account' => 25,
                'amount' => '100',
                'category' => 21,
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

        self::assertArrayHasKey('errors', $content);
        self::assertArrayHasKey('0', $content['errors']);

        $firstItemErrors = $content['errors']['0'];
        self::assertIsArray($firstItemErrors);
        self::assertNotEmpty($firstItemErrors);

        $firstMessage = (string) $firstItemErrors[0];
        self::assertStringContainsStringIgnoringCase('type', $firstMessage);
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
    public function testBulkCreateWithCompensationsPersistsExpenseAndLinkedIncomes(): void
    {
        $expenseNote = 'bulk-expense-with-compensation';
        $compensationNote = 'bulk-compensation-income';

        $payload = [
            [
                'type' => 'expense',
                'account' => 25,
                'amount' => '100',
                'category' => 21,
                'executedAt' => '2026-02-22T13:22:00.000Z',
                'isDraft' => false,
                'note' => $expenseNote,
                'compensations' => [
                    [
                        'type' => 'income',
                        'account' => 25,
                        'amount' => '50',
                        'category' => 137,
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
        /** @var Account|null $accountBefore */
        $accountBefore = $this->em->getRepository(Account::class)->find(25);
        self::assertNotNull($accountBefore, 'Account #25 must exist in test fixtures.');
        $balanceBefore = $accountBefore->getBalance();

        $incomeNote = 'bulk-balance-check-income';
        $expenseNote = 'bulk-balance-check-expense';

        $payload = [
            [
                'type' => 'income',
                'account' => 25,
                'amount' => '500',
                'category' => 137,
                'executedAt' => '2026-02-22T13:25:00.000Z',
                'isDraft' => false,
                'note' => $incomeNote,
            ],
            [
                'type' => 'expense',
                'account' => 25,
                'amount' => '100',
                'category' => 21,
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
        $accountAfter = $this->em->getRepository(Account::class)->find(25);
        self::assertNotNull($accountAfter, 'Account #25 must exist after bulk creation.');
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
}