<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Debt;
use App\Entity\ExchangeRateSnapshot;
use App\Entity\Expense;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ExchangeRateSnapshotRepository;
use App\Repository\TransactionRepository;
use App\Service\AssetsManager;
use App\Service\ExchangeRateSnapshotResolver;
use App\Service\FixerService;
use ArrayIterator;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Security;

class AssetsManagerTest extends TestCase
{
    public function testGenerateTransactionPaginationDataResolvesDescendantsAndRoundsTotal(): void
    {
        $resolvedCategories = [101, 102, 103];
        $list = new ArrayIterator(['tx-1', 'tx-2']);

        $paginator = $this->createMock(Paginator::class);
        $paginator->method('getIterator')->willReturn($list);
        $paginator->method('count')->willReturn(22);

        $transactionRepo = $this->createMock(TransactionRepository::class);
        $transactionRepo
            ->expects(self::once())
            ->method('getPaginator')
            ->with(
                after: null,
                before: null,
                affectingProfitOnly: false,
                type: Transaction::EXPENSE,
                categories: $resolvedCategories,
                accounts: [3],
                excludedCategories: [99],
                isDraft: false,
                note: 'coffee',
                amountGte: 10.0,
                amountLte: 40.0,
                debts: [5],
                currencies: ['EUR'],
                limit: 50,
                page: 2,
                orderField: 'executedAt',
                order: 'ASC',
            )
            ->willReturn($paginator);

        $transactionRepo
            ->expects(self::once())
            ->method('sumConverted')
            ->willReturnCallback(
                static function (
                    string $baseCurrency,
                    $after,
                    $before,
                    bool $affectingProfitOnly,
                    ?string $type,
                    ?array $categories,
                    ?array $accounts,
                    ?array $excludedCategories,
                    ?bool $isDraft,
                    ?string $note,
                    ?float $amountGte,
                    ?float $amountLte,
                    ?array $debts,
                    ?array $currencies,
                ): float {
                    self::assertSame('USD', $baseCurrency);
                    self::assertNull($after);
                    self::assertNull($before);
                    self::assertFalse($affectingProfitOnly);
                    self::assertSame(Transaction::EXPENSE, $type);
                    self::assertSame([101, 102, 103], $categories);
                    self::assertSame([3], $accounts);
                    self::assertSame([99], $excludedCategories);
                    self::assertFalse($isDraft);
                    self::assertSame('coffee', $note);
                    self::assertSame(10.0, $amountGte);
                    self::assertSame(40.0, $amountLte);
                    self::assertSame([5], $debts);
                    self::assertSame(['EUR'], $currencies);

                    return 12.3456;
                },
            );

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo
            ->expects(self::once())
            ->method('getCategoriesWithDescendantsByType')
            ->with([10], Transaction::EXPENSE)
            ->willReturn($resolvedCategories);

        $assetsManager = $this->createAssetsManager(
            $transactionRepo,
            $categoryRepo,
            $this->createResolverReturningSnapshot(
                (new ExchangeRateSnapshot())
                    ->setEffectiveAt(new DateTimeImmutable('2024-01-01T00:00:00+00:00'))
                    ->setUsdPerEur('2.0'),
            ),
        );

        $result = $assetsManager->generateTransactionPaginationData(
            after: null,
            before: null,
            type: Transaction::EXPENSE,
            categories: [10],
            accounts: [3],
            excludedCategories: [99],
            withChildCategories: true,
            isDraft: false,
            note: 'coffee',
            amountGte: 10.0,
            amountLte: 40.0,
            debts: [5],
            currencies: ['EUR'],
            perPage: 50,
            page: 2,
            orderField: 'executedAt',
            order: 'ASC',
        );

        self::assertSame($list, $result['list']);
        self::assertSame(22, $result['count']);
        self::assertSame(12.35, $result['totalValue']);
    }

    public function testGenerateTransactionPaginationDataSkipsDescendantResolutionWhenDisabled(): void
    {
        $paginator = $this->createMock(Paginator::class);
        $paginator->method('getIterator')->willReturn(new ArrayIterator([]));
        $paginator->method('count')->willReturn(0);

        $transactionRepo = $this->createMock(TransactionRepository::class);
        $transactionRepo->expects(self::once())->method('getPaginator')->willReturn($paginator);
        $transactionRepo
            ->expects(self::once())
            ->method('sumConverted')
            ->willReturnCallback(static function (string $baseCurrency, $after, $before, bool $affectingProfitOnly, ?string $type, ?array $categories): float {
                self::assertSame('USD', $baseCurrency);
                self::assertSame([10], $categories);

                return 0.0;
            });

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->expects(self::never())->method('getCategoriesWithDescendantsByType');

        $assetsManager = $this->createAssetsManager(
            $transactionRepo,
            $categoryRepo,
            $this->createResolverReturningSnapshot(
                (new ExchangeRateSnapshot())
                    ->setEffectiveAt(new DateTimeImmutable('2024-01-01T00:00:00+00:00'))
                    ->setUsdPerEur('2.0'),
            ),
        );

        $assetsManager->generateTransactionPaginationData(
            after: null,
            before: null,
            type: null,
            categories: [10],
            withChildCategories: false,
        );
    }

    public function testSumTransactionsSupportsBaseAndExplicitCurrency(): void
    {
        $tx1 = $this->createMock(Transaction::class);
        $tx1->method('getConvertedValue')->willReturnCallback(
            static fn (?string $currency = null) => 'EUR' === $currency ? 10.5 : 20.0,
        );

        $tx2 = $this->createMock(Transaction::class);
        $tx2->method('getConvertedValue')->willReturnCallback(
            static fn (?string $currency = null) => 'EUR' === $currency ? 1.25 : 2.0,
        );

        $assetsManager = $this->createAssetsManager(
            $this->createMock(TransactionRepository::class),
            $this->createMock(CategoryRepository::class),
            $this->createResolverReturningSnapshot(
                (new ExchangeRateSnapshot())
                    ->setEffectiveAt(new DateTimeImmutable('2024-01-01T00:00:00+00:00'))
                    ->setUsdPerEur('2.0'),
            ),
        );

        self::assertSame(22.0, $assetsManager->sumTransactions([$tx1, $tx2]));
        self::assertSame(11.75, $assetsManager->sumTransactions([$tx1, $tx2], 'EUR'));
    }

    public function testConvertForTransactionUsesExecutedAtDate(): void
    {
        $snapshot = (new ExchangeRateSnapshot())
            ->setEffectiveAt(new DateTimeImmutable('2024-01-02T00:00:00+00:00'))
            ->setUsdPerEur('2.0')
            ->setUahPerEur('40.0');

        $snapshotRepo = $this->createMock(ExchangeRateSnapshotRepository::class);
        $snapshotRepo
            ->expects(self::once())
            ->method('findClosestSnapshot')
            ->with(self::callback(static fn ($date) => '2024-01-15' === $date->format('Y-m-d')))
            ->willReturn($snapshot);

        $resolver = $this->createResolver($snapshotRepo);

        $transaction = $this->createMock(Expense::class);
        $transaction->method('getValuableField')->willReturn('amount');
        $transaction->method('getAmount')->willReturn(100.0);
        $transaction->method('getCurrency')->willReturn('EUR');
        $transaction->method('getExecutedAt')->willReturn(CarbonImmutable::parse('2024-01-15T10:00:00+00:00'));

        $assetsManager = $this->createAssetsManager(
            $this->createMock(TransactionRepository::class),
            $this->createMock(CategoryRepository::class),
            $resolver,
        );

        $converted = $assetsManager->convert($transaction);

        self::assertSame(100.0, $converted['EUR']);
        self::assertSame(200.0, $converted['USD']);
        self::assertSame(4000.0, $converted['UAH']);
    }

    public function testConvertForDebtUsesCurrentDate(): void
    {
        $snapshot = (new ExchangeRateSnapshot())
            ->setEffectiveAt(new DateTimeImmutable('2024-01-02T00:00:00+00:00'))
            ->setUsdPerEur('2.0');

        $snapshotRepo = $this->createMock(ExchangeRateSnapshotRepository::class);
        $snapshotRepo
            ->expects(self::once())
            ->method('findClosestSnapshot')
            ->with(self::callback(static function ($date): bool {
                return $date instanceof DateTimeInterface
                    && CarbonImmutable::instance($date)->toDateString() === CarbonImmutable::today()->toDateString()
                    && 0 === CarbonImmutable::instance($date)->hour;
            }))
            ->willReturn($snapshot);

        $resolver = $this->createResolver($snapshotRepo);

        $debt = (new Debt())
            ->setCurrency('EUR')
            ->setBalance(15);

        $assetsManager = $this->createAssetsManager(
            $this->createMock(TransactionRepository::class),
            $this->createMock(CategoryRepository::class),
            $resolver,
        );

        $converted = $assetsManager->convert($debt);
        self::assertSame(15.0, $converted['EUR']);
        self::assertSame(30.0, $converted['USD']);
    }

    public function testConvertThrowsWhenTransactionHasNoExecutedAt(): void
    {
        $snapshotRepo = $this->createMock(ExchangeRateSnapshotRepository::class);
        $snapshotRepo->expects(self::never())->method('findClosestSnapshot');
        $resolver = $this->createResolver($snapshotRepo);

        $transaction = $this->createMock(Expense::class);
        $transaction->method('getValuableField')->willReturn('amount');
        $transaction->method('getAmount')->willReturn(100.0);
        $transaction->method('getCurrency')->willReturn('EUR');
        $transaction->method('getExecutedAt')->willReturn(null);

        $assetsManager = $this->createAssetsManager(
            $this->createMock(TransactionRepository::class),
            $this->createMock(CategoryRepository::class),
            $resolver,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction has no valid executedAt date for FX lookup.');

        $assetsManager->convert($transaction);
    }

    private function createAssetsManager(
        TransactionRepository $transactionRepository,
        CategoryRepository $categoryRepository,
        ExchangeRateSnapshotResolver $resolver,
    ): AssetsManager {
        $user = (new User())
            ->setUsername('assets-manager-test')
            ->setBaseCurrency('USD');

        /** @var MockObject&Security $security */
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return new AssetsManager($transactionRepository, $categoryRepository, $resolver, $security);
    }

    private function createResolver(ExchangeRateSnapshotRepository $snapshotRepo): ExchangeRateSnapshotResolver
    {
        /** @var MockObject&EntityManagerInterface $em */
        $em = $this->createMock(EntityManagerInterface::class);
        /** @var MockObject&FixerService $fixer */
        $fixer = $this->createMock(FixerService::class);

        return new ExchangeRateSnapshotResolver($snapshotRepo, $em, $fixer);
    }

    private function createResolverReturningSnapshot(ExchangeRateSnapshot $snapshot): ExchangeRateSnapshotResolver
    {
        $snapshotRepo = $this->createMock(ExchangeRateSnapshotRepository::class);
        $snapshotRepo->method('findClosestSnapshot')->willReturn($snapshot);

        return $this->createResolver($snapshotRepo);
    }
}
