<?php

namespace App\Tests\Bank;

use App\Bank\BankProvider;
use App\Bank\BankProviderRegistry;
use App\Bank\BankWebhookService;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\Provider\MonobankProvider;
use App\Bank\WebhookCapableInterface;
use App\Entity\BankCardAccount;
use App\Entity\Expense;
use App\Entity\Income;
use App\Tests\BaseApiTestCase;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * Integration tests for BankWebhookService::handle().
 * Uses the real DB (transactions rolled back by DAMA after each test).
 *
 * @group bank
 */
class BankWebhookServiceTest extends BaseApiTestCase
{
    private const EXTERNAL_ID = 'mono_acc_test_001';

    /** @var BankCardAccount */
    private BankCardAccount $uahCard;

    /** @var \PHPUnit\Framework\MockObject\MockObject&MonobankProvider */
    private \PHPUnit\Framework\MockObject\MockObject $mockWebhookProvider;

    private BankWebhookService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Link the existing UAH Card to a known externalAccountId.
        /** @var BankCardAccount $card */
        $card = $this->em->getRepository(BankCardAccount::class)->findOneBy([
            'name'  => 'UAH Card',
            'owner' => $this->testUser,
        ]);
        self::assertNotNull($card);
        $this->uahCard = $card;
        $this->uahCard->setExternalAccountId(self::EXTERNAL_ID);
        $this->em->flush();

        // Build a mock of MonobankProvider (implements BankProviderInterface + WebhookCapableInterface).
        $this->mockWebhookProvider = $this->createMock(MonobankProvider::class);
        $this->mockWebhookProvider->method('getProvider')->willReturn(BankProvider::Monobank);

        $registry = new BankProviderRegistry([$this->mockWebhookProvider]);

        $this->service = new BankWebhookService($registry, $this->em, new NullLogger());
    }

    // -------------------------------------------------------------------------
    // Payload routing and validation
    // -------------------------------------------------------------------------

    public function testNonWebhookCapableProviderThrowsLogicException(): void
    {
        // Replace registry with one that has a non-webhook-capable provider stub.
        $nonWebhookProvider = $this->createMock(\App\Bank\BankProviderInterface::class);
        $nonWebhookProvider->method('getProvider')->willReturn(BankProvider::Wise);

        $registry = new BankProviderRegistry([$nonWebhookProvider]);
        $service  = new BankWebhookService($registry, $this->em, new NullLogger());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/wise/i');

        $service->handle(BankProvider::Wise, []);
    }

    public function testNullPayloadFromProviderReturnsNull(): void
    {
        $this->mockWebhookProvider->method('parseWebhookPayload')->willReturn(null);

        $result = $this->service->handle(BankProvider::Monobank, ['type' => 'PingConfirmation']);

        self::assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Account resolution
    // -------------------------------------------------------------------------

    public function testUnknownExternalAccountIdReturnsNull(): void
    {
        $this->mockWebhookProvider->method('parseWebhookPayload')->willReturn(
            new DraftTransactionData(
                externalAccountId: 'does_not_exist',
                amount: -50.0,
                executedAt: new DateTimeImmutable(),
                note: 'Test',
            ),
        );

        $result = $this->service->handle(BankProvider::Monobank, []);

        self::assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Deduplication
    // -------------------------------------------------------------------------

    public function testDuplicateTransactionIsSkipped(): void
    {
        $executedAt = new DateTimeImmutable('2025-06-01 12:00:00');

        // Pre-persist an identical transaction.
        $category = $this->em->getRepository(\App\Entity\ExpenseCategory::class)
            ->find(\App\Entity\Category::EXPENSE_CATEGORY_ID_UNKNOWN);
        $existing = new Expense();
        $existing
            ->setAmount(75.0)
            ->setExecutedAt($executedAt)
            ->setAccount($this->uahCard)
            ->setCategory($category)
            ->setOwner($this->testUser);
        $this->em->persist($existing);
        $this->em->flush();

        $this->mockWebhookProvider->method('parseWebhookPayload')->willReturn(
            new DraftTransactionData(
                externalAccountId: self::EXTERNAL_ID,
                amount: -75.0,
                executedAt: $executedAt,
                note: 'Duplicate',
            ),
        );

        $result = $this->service->handle(BankProvider::Monobank, []);

        self::assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Transaction creation
    // -------------------------------------------------------------------------

    public function testExpensePayloadCreatesExpenseTransaction(): void
    {
        $this->mockWebhookProvider->method('parseWebhookPayload')->willReturn(
            new DraftTransactionData(
                externalAccountId: self::EXTERNAL_ID,
                amount: -120.50,
                executedAt: new DateTimeImmutable('2025-06-02 09:00:00'),
                note: 'Supermarket',
            ),
        );

        $result = $this->service->handle(BankProvider::Monobank, []);

        self::assertInstanceOf(Expense::class, $result);
        self::assertEqualsWithDelta(120.50, $result->getAmount(), 0.001);
        self::assertSame('Supermarket', $result->getNote());
        self::assertSame($this->uahCard->getId(), $result->getAccount()->getId());
        self::assertNotNull($result->getId(), 'Transaction must be persisted');
    }

    public function testIncomePayloadCreatesIncomeTransaction(): void
    {
        $this->mockWebhookProvider->method('parseWebhookPayload')->willReturn(
            new DraftTransactionData(
                externalAccountId: self::EXTERNAL_ID,
                amount: 5000.0,
                executedAt: new DateTimeImmutable('2025-06-03 10:00:00'),
                note: 'Salary',
            ),
        );

        $result = $this->service->handle(BankProvider::Monobank, []);

        self::assertInstanceOf(Income::class, $result);
        self::assertEqualsWithDelta(5000.0, $result->getAmount(), 0.001);
    }

    public function testCreatedTransactionIsMarkedAsDraft(): void
    {
        $this->mockWebhookProvider->method('parseWebhookPayload')->willReturn(
            new DraftTransactionData(
                externalAccountId: self::EXTERNAL_ID,
                amount: -30.0,
                executedAt: new DateTimeImmutable('2025-06-04 08:00:00'),
                note: 'Coffee',
            ),
        );

        $result = $this->service->handle(BankProvider::Monobank, []);

        self::assertTrue($result->getIsDraft());
    }
}
