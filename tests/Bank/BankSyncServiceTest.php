<?php

namespace App\Tests\Bank;

use App\Bank\BankProvider;
use App\Bank\BankProviderRegistry;
use App\Bank\BankSyncService;
use App\Bank\DTO\DraftTransactionData;
use App\Bank\BankProviderInterface;
use App\Bank\PollingCapableInterface;
use App\Bank\Provider\MonobankProvider;
use App\DTO\CategorizationResult;
use App\Entity\BankCardAccount;
use App\Entity\BankIntegration;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\Income;
use App\Bank\SyncMethod;
use App\Repository\BankIntegrationRepository;
use App\Service\TransactionCategorizationService;
use App\Tests\BaseApiTestCase;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * Integration tests for BankSyncService.
 * Uses the real test DB (rolled back by DAMA after each test).
 *
 * @group bank
 */
interface TestPollingBankProvider extends BankProviderInterface, PollingCapableInterface {}

class BankSyncServiceTest extends BaseApiTestCase
{
    private const EXTERNAL_ID = 'wise_bal_test_001';

    private BankCardAccount $uahCard;
    private BankIntegration $integration;

    /** @var \PHPUnit\Framework\MockObject\MockObject&TestPollingBankProvider */
    private \PHPUnit\Framework\MockObject\MockObject $mockPollingProvider;

    private BankSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a Wise BankIntegration.
        $this->integration = new BankIntegration();
        $this->integration
            ->setOwner($this->testUser)
            ->setProvider(BankProvider::Wise)
            ->setSyncMethod(SyncMethod::Polling)
            ->setIsActive(true);
        $this->em->persist($this->integration);

        // Link the UAH Card to the integration + set externalAccountId.
        /** @var BankCardAccount $card */
        $card = $this->em->getRepository(BankCardAccount::class)->findOneBy([
            'name'  => 'UAH Card',
            'owner' => $this->testUser,
        ]);
        self::assertNotNull($card);
        $this->uahCard = $card;
        $this->uahCard
            ->setExternalAccountId(self::EXTERNAL_ID)
            ->setBankIntegration($this->integration);

        $this->em->flush();

        // Build mock of a combined polling-capable provider.
        $this->mockPollingProvider = $this->createMock(TestPollingBankProvider::class);
        $this->mockPollingProvider->method('getProvider')->willReturn(BankProvider::Wise);

        // Also register a Monobank stub (non-polling) so syncAll doesn't throw
        // when it encounters the Monobank integration created by fixtures.
        $monobankStub = $this->createMock(MonobankProvider::class);
        $monobankStub->method('getProvider')->willReturn(BankProvider::Monobank);

        $registry = new BankProviderRegistry([$this->mockPollingProvider, $monobankStub]);

        $this->service = new BankSyncService(
            $registry,
            $this->em->getRepository(BankIntegration::class),
            $this->em,
            new NullLogger(),
            $this->makeCategorizationServiceMock(),
        );
    }

    private function makeCategorizationServiceMock(): TransactionCategorizationService
    {
        $mock = $this->createMock(TransactionCategorizationService::class);
        $mock->method('suggest')->willReturnCallback(
            static function (string $rawNote, bool $isIncome): CategorizationResult {
                $fallbackId = $isIncome
                    ? Category::INCOME_CATEGORY_ID_UNKNOWN
                    : Category::EXPENSE_CATEGORY_ID_UNKNOWN;

                return new CategorizationResult($fallbackId, $rawNote, 0.0);
            }
        );

        return $mock;
    }

    // -------------------------------------------------------------------------
    // syncById
    // -------------------------------------------------------------------------

    public function testSyncByIdThrowsWhenIntegrationNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->syncById(999999);
    }

    // -------------------------------------------------------------------------
    // sync — provider capability checks
    // -------------------------------------------------------------------------

    public function testSyncThrowsLogicExceptionForNonPollingProvider(): void
    {
        // Build a registry with a non-polling provider only.
        $nonPollingProvider = $this->createMock(\App\Bank\BankProviderInterface::class);
        $nonPollingProvider->method('getProvider')->willReturn(BankProvider::Monobank);

        $registry = new BankProviderRegistry([$nonPollingProvider]);
        $service  = new BankSyncService(
            $registry,
            $this->em->getRepository(BankIntegration::class),
            $this->em,
            new NullLogger(),
            $this->makeCategorizationServiceMock(),
        );

        // Create a Monobank integration (non-polling).
        $mono = new BankIntegration();
        $mono->setOwner($this->testUser)
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setIsActive(true);
        $this->em->persist($mono);
        $this->em->flush();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/monobank/i');

        $service->sync($mono);
    }

    // -------------------------------------------------------------------------
    // sync — no linked accounts
    // -------------------------------------------------------------------------

    public function testSyncReturnsZeroWhenNoLinkedAccounts(): void
    {
        // Detach card from integration.
        $this->uahCard->setBankIntegration(null);
        $this->em->flush();

        $this->mockPollingProvider->expects(self::never())->method('fetchTransactions');

        $result = $this->service->sync($this->integration);

        self::assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // sync — account without externalAccountId
    // -------------------------------------------------------------------------

    public function testSyncSkipsAccountWithoutExternalId(): void
    {
        $this->uahCard->setExternalAccountId(null);
        $this->em->flush();

        $this->mockPollingProvider->expects(self::never())->method('fetchTransactions');

        $result = $this->service->sync($this->integration);

        self::assertSame(0, $result);
    }

    // -------------------------------------------------------------------------
    // sync — new transactions
    // -------------------------------------------------------------------------

    public function testSyncCreatesExpenseDraftForNegativeAmount(): void
    {
        $draft = new DraftTransactionData(
            externalAccountId: self::EXTERNAL_ID,
            amount: -200.0,
            executedAt: new DateTimeImmutable('2025-06-01 11:00:00'),
            note: 'Wise payment',
        );

        $this->mockPollingProvider
            ->method('fetchTransactions')
            ->willReturn([$draft]);

        $created = $this->service->sync($this->integration);

        self::assertSame(1, $created);

        // Verify the draft was actually persisted.
        $this->em->clear();
        $found = $this->em->getRepository(Expense::class)->findBy([
            'account'  => $this->uahCard,
            'isDraft'  => true,
        ]);
        self::assertCount(1, $found);
        self::assertEqualsWithDelta(200.0, $found[0]->getAmount(), 0.001);
        self::assertTrue($found[0]->getIsDraft());
    }

    public function testSyncCreatesIncomeDraftForPositiveAmount(): void
    {
        $draft = new DraftTransactionData(
            externalAccountId: self::EXTERNAL_ID,
            amount: 3500.0,
            executedAt: new DateTimeImmutable('2025-06-02 09:00:00'),
            note: 'Transfer received',
        );

        $this->mockPollingProvider
            ->method('fetchTransactions')
            ->willReturn([$draft]);

        $created = $this->service->sync($this->integration);

        self::assertSame(1, $created);

        $this->em->clear();
        $found = $this->em->getRepository(Income::class)->findBy(['account' => $this->uahCard]);
        self::assertCount(1, $found);
        self::assertEqualsWithDelta(3500.0, $found[0]->getAmount(), 0.001);
    }

    public function testSyncUpdatesLastSyncedAt(): void
    {
        $this->mockPollingProvider->method('fetchTransactions')->willReturn([]);

        // The card has no externalAccountId now — but we restore it so the query runs.
        $before = new DateTimeImmutable('-1 minute');

        $this->service->sync($this->integration);

        $this->em->clear();
        $refreshed = $this->em->getRepository(BankIntegration::class)->find($this->integration->getId());
        self::assertNotNull($refreshed->getLastSyncedAt());
        self::assertGreaterThan($before, $refreshed->getLastSyncedAt());
    }

    // -------------------------------------------------------------------------
    // sync — deduplication
    // -------------------------------------------------------------------------

    public function testDuplicateTransactionIsNotCreatedAgain(): void
    {
        $executedAt = new DateTimeImmutable('2025-06-03 14:00:00');

        // Pre-persist the same transaction.
        $category = $this->em->getRepository(\App\Entity\ExpenseCategory::class)
            ->find(\App\Entity\Category::EXPENSE_CATEGORY_ID_UNKNOWN);
        $existing = new Expense();
        $existing
            ->setAmount(50.0)
            ->setExecutedAt($executedAt)
            ->setAccount($this->uahCard)
            ->setCategory($category)
            ->setOwner($this->testUser);
        $this->em->persist($existing);
        $this->em->flush();

        $draft = new DraftTransactionData(
            externalAccountId: self::EXTERNAL_ID,
            amount: -50.0,
            executedAt: $executedAt,
            note: 'Duplicate',
        );

        $this->mockPollingProvider->method('fetchTransactions')->willReturn([$draft]);

        $created = $this->service->sync($this->integration);

        self::assertSame(0, $created);
    }

    // -------------------------------------------------------------------------
    // syncAll
    // -------------------------------------------------------------------------

    public function testSyncAllSkipsInactiveIntegrations(): void
    {
        $this->integration->setIsActive(false);
        $this->em->flush();

        $this->mockPollingProvider->expects(self::never())->method('fetchTransactions');

        $results = $this->service->syncAll();

        self::assertArrayNotHasKey($this->integration->getId(), $results);
    }

    public function testSyncAllSkipsWebhookForcedIntegrations(): void
    {
        // Create a new Wise integration explicitly forced to webhook mode.
        $webhookIntegration = new BankIntegration();
        $webhookIntegration
            ->setOwner($this->testUser)
            ->setProvider(BankProvider::Wise)
            ->setSyncMethod(SyncMethod::Webhook)  // forced — should be skipped in syncAll
            ->setIsActive(true);
        $this->em->persist($webhookIntegration);
        $this->em->flush();

        $results = $this->service->syncAll();

        self::assertArrayNotHasKey($webhookIntegration->getId(), $results);
    }

    public function testSyncAllRecordsZeroOnProviderException(): void
    {
        $this->mockPollingProvider
            ->method('fetchTransactions')
            ->willThrowException(new \RuntimeException('Wise API timeout'));

        $results = $this->service->syncAll();

        self::assertArrayHasKey($this->integration->getId(), $results);
        self::assertSame(0, $results[$this->integration->getId()]);
    }
}
