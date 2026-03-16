<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExchangeRateSnapshot;
use App\Repository\ExchangeRateSnapshotRepository;
use App\Service\ExchangeRateSnapshotResolver;
use App\Service\FixerService;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExchangeRateSnapshotResolverTest extends TestCase
{
    private const FIXER_RATES = ['EUR' => 1.0, 'USD' => 1.1, 'HUF' => 380.0, 'UAH' => 40.0];

    private ExchangeRateSnapshotRepository&MockObject $snapshotRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private FixerService&MockObject $fixerService;
    private ExchangeRateSnapshotResolver $resolver;

    protected function setUp(): void
    {
        $this->snapshotRepository = $this->createMock(ExchangeRateSnapshotRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->fixerService = $this->createMock(FixerService::class);

        $this->resolver = new ExchangeRateSnapshotResolver(
            $this->snapshotRepository,
            $this->entityManager,
            $this->fixerService,
        );
    }

    // --- getClosestOrFetch ---

    public function testGetClosestOrFetch_withExistingSnapshot_returnsItWithoutCallingFixer(): void
    {
        $date = CarbonImmutable::parse('2026-03-10');
        $snapshot = $this->createSnapshotStub($date);

        $this->snapshotRepository
            ->expects(self::once())
            ->method('findClosestSnapshot')
            ->willReturn($snapshot);

        $this->fixerService->expects(self::never())->method('getLatest');
        $this->fixerService->expects(self::never())->method('getHistorical');

        $result = $this->resolver->getClosestOrFetch($date);

        self::assertSame($snapshot, $result);
    }

    public function testGetClosestOrFetch_todayNoSnapshot_fetchesFromFixerAndPersists(): void
    {
        $today = CarbonImmutable::today();

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);

        $this->fixerService
            ->expects(self::once())
            ->method('getLatest')
            ->willReturn(self::FIXER_RATES);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->resolver->getClosestOrFetch($today);

        self::assertEquals($today->toDateString(), $result->getEffectiveAt()->format('Y-m-d'));
        self::assertEquals('1.1', $result->getUsdPerEur());
        self::assertEquals('380', $result->getHufPerEur());
        self::assertEquals('40', $result->getUahPerEur());
    }

    public function testGetClosestOrFetch_pastDateNoSnapshot_fetchesHistoricalAndPersists(): void
    {
        $pastDate = CarbonImmutable::parse('2026-02-15');

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);

        $this->fixerService
            ->expects(self::once())
            ->method('getHistorical')
            ->with($pastDate)
            ->willReturn(self::FIXER_RATES);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->resolver->getClosestOrFetch($pastDate);

        self::assertEquals('2026-02-15', $result->getEffectiveAt()->format('Y-m-d'));
    }

    public function testGetClosestOrFetch_futureDate_throwsException(): void
    {
        $futureDate = CarbonImmutable::now()->addMonth();

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('future date');

        $this->resolver->getClosestOrFetch($futureDate);
    }

    // --- getRatesForDate ---

    public function testGetRatesForDate_withExactSnapshot_returnsRatesWithoutCallingFixer(): void
    {
        $date = CarbonImmutable::parse('2026-03-10');
        $snapshot = $this->createSnapshotStub($date);

        $this->snapshotRepository
            ->expects(self::once())
            ->method('findExactSnapshot')
            ->willReturn($snapshot);

        $this->fixerService->expects(self::never())->method('getLatest');
        $this->fixerService->expects(self::never())->method('getHistorical');

        $rates = $this->resolver->getRatesForDate($date);

        self::assertArrayHasKey('EUR', $rates);
        self::assertSame(1.0, $rates['EUR']);
        self::assertArrayHasKey('USD', $rates);
        self::assertArrayHasKey('HUF', $rates);
        self::assertArrayHasKey('UAH', $rates);
    }

    public function testGetRatesForDate_noExactSnapshot_fetchesAndPersists(): void
    {
        $date = CarbonImmutable::parse('2026-03-10');

        $this->snapshotRepository->method('findExactSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);

        $this->fixerService
            ->expects(self::once())
            ->method('getHistorical')
            ->willReturn(self::FIXER_RATES);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $rates = $this->resolver->getRatesForDate($date);

        self::assertSame(1.1, $rates['USD']);
    }

    public function testGetRatesForDate_futureWithClosestSnapshot_usesClosest(): void
    {
        $futureDate = CarbonImmutable::now()->addMonth();
        $todaySnapshot = $this->createSnapshotStub(CarbonImmutable::today());

        $this->snapshotRepository->method('findExactSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findClosestSnapshot')->willReturn($todaySnapshot);

        $this->fixerService->expects(self::never())->method('getLatest');
        $this->fixerService->expects(self::never())->method('getHistorical');

        $rates = $this->resolver->getRatesForDate($futureDate);

        self::assertArrayHasKey('EUR', $rates);
    }

    // --- fetchAndPersistSnapshot race condition ---

    public function testGetClosestOrFetch_raceCondition_readsExistingOnSecondAttempt(): void
    {
        $today = CarbonImmutable::today();
        $existingSnapshot = $this->createSnapshotStub($today);

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);

        // First findOneBy returns null (before insert), then returns existing (after race)
        // But since fetchAndPersistSnapshot re-checks, simulate that the second findOneBy returns existing
        $this->snapshotRepository
            ->method('findOneBy')
            ->willReturn($existingSnapshot);

        // Fixer should NOT be called because findOneBy returns existing on re-check
        $this->fixerService->expects(self::never())->method('getLatest');

        $result = $this->resolver->getClosestOrFetch($today);

        self::assertSame($existingSnapshot, $result);
    }

    // --- fetchAndPersistSnapshot: empty / null rates from Fixer ---

    public function testGetClosestOrFetch_fixerReturnsEmptyArray_throwsRuntimeException(): void
    {
        $pastDate = CarbonImmutable::parse('2026-01-10');

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);
        $this->fixerService->method('getHistorical')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No rates returned from Fixer/');

        $this->resolver->getClosestOrFetch($pastDate);
    }

    public function testGetClosestOrFetch_fixerReturnsNull_throwsRuntimeException(): void
    {
        $pastDate = CarbonImmutable::parse('2026-01-10');

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);
        $this->fixerService->method('getHistorical')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No rates returned from Fixer/');

        $this->resolver->getClosestOrFetch($pastDate);
    }

    public function testGetClosestOrFetch_fixerThrowsException_wrapsInRuntimeException(): void
    {
        $pastDate = CarbonImmutable::parse('2026-01-10');

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);
        $this->fixerService
            ->method('getHistorical')
            ->willThrowException(new \RuntimeException('API key invalid'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to fetch rates for/');

        $this->resolver->getClosestOrFetch($pastDate);
    }

    // --- fetchAndPersistSnapshot: UniqueConstraintViolationException (race condition) ---

    public function testGetClosestOrFetch_uniqueConstraintViolation_returnsExistingSnapshot(): void
    {
        $today = CarbonImmutable::today();
        $raceSnapshot = $this->createSnapshotStub($today);

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        // First findOneBy: null (pre-insert); second findOneBy: existing (post-race)
        $this->snapshotRepository
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls(null, $raceSnapshot);

        $this->fixerService->method('getLatest')->willReturn(self::FIXER_RATES);
        $this->entityManager->method('persist');
        $this->entityManager
            ->method('flush')
            ->willThrowException($this->buildUniqueConstraintViolationException());

        $result = $this->resolver->getClosestOrFetch($today);

        self::assertSame($raceSnapshot, $result);
    }

    public function testGetClosestOrFetch_uniqueConstraintViolationAndSnapshotGone_throwsRuntimeException(): void
    {
        $today = CarbonImmutable::today();

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null); // always null

        $this->fixerService->method('getLatest')->willReturn(self::FIXER_RATES);
        $this->entityManager->method('persist');
        $this->entityManager
            ->method('flush')
            ->willThrowException($this->buildUniqueConstraintViolationException());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Race condition/');

        $this->resolver->getClosestOrFetch($today);
    }

    // --- applyRatesToSnapshot: BTC/ETH are stored as EUR-per-unit (inverse of Fixer value) ---

    public function testGetClosestOrFetch_btcRateStoredAsEurPerBtcInverse(): void
    {
        $pastDate = CarbonImmutable::parse('2026-01-10');
        $btcPerEurFromFixer = 0.000025; // Fixer gives BTC rate relative to EUR base

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);
        $this->fixerService
            ->method('getHistorical')
            ->willReturn(['EUR' => 1.0, 'BTC' => $btcPerEurFromFixer]);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $snapshot = $this->resolver->getClosestOrFetch($pastDate);

        $expectedEurPerBtc = 1.0 / $btcPerEurFromFixer; // 40000
        self::assertEqualsWithDelta($expectedEurPerBtc, $snapshot->getEurPerBtcFloat(), 1.0);
    }

    public function testGetClosestOrFetch_ethRateStoredAsEurPerEthInverse(): void
    {
        $pastDate = CarbonImmutable::parse('2026-01-10');
        $ethPerEurFromFixer = 0.0005; // 1 EUR = 0.0005 ETH → 1 ETH = 2000 EUR

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);
        $this->fixerService
            ->method('getHistorical')
            ->willReturn(['EUR' => 1.0, 'ETH' => $ethPerEurFromFixer]);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $snapshot = $this->resolver->getClosestOrFetch($pastDate);

        $expectedEurPerEth = 1.0 / $ethPerEurFromFixer; // 2000
        self::assertEqualsWithDelta($expectedEurPerEth, $snapshot->getEurPerEthFloat(), 0.01);
    }

    public function testGetClosestOrFetch_partialRates_unsetFieldsRemainNull(): void
    {
        $pastDate = CarbonImmutable::parse('2026-01-10');

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);
        $this->fixerService
            ->method('getHistorical')
            ->willReturn(['EUR' => 1.0, 'USD' => 1.09]);
        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $snapshot = $this->resolver->getClosestOrFetch($pastDate);

        self::assertNotNull($snapshot->getUsdPerEurFloat());
        self::assertNull($snapshot->getHufPerEurFloat());
        self::assertNull($snapshot->getUahPerEurFloat());
        self::assertNull($snapshot->getEurPerBtcFloat());
        self::assertNull($snapshot->getEurPerEthFloat());
    }

    // --- snapshotToRatesArray: null fields are excluded from result ---

    public function testGetRatesForDate_nullFieldsNotIncludedInRatesArray(): void
    {
        $date = CarbonImmutable::parse('2026-03-10');
        $snapshot = new ExchangeRateSnapshot();
        $snapshot->setEffectiveAt($date);
        $snapshot->setUsdPerEur('1.1');
        // HUF, UAH, BTC, ETH intentionally not set

        $this->snapshotRepository->method('findExactSnapshot')->willReturn($snapshot);

        $rates = $this->resolver->getRatesForDate($date);

        self::assertArrayHasKey('EUR', $rates);
        self::assertArrayHasKey('USD', $rates);
        self::assertArrayNotHasKey('HUF', $rates);
        self::assertArrayNotHasKey('UAH', $rates);
        self::assertArrayNotHasKey('BTC', $rates);
        self::assertArrayNotHasKey('ETH', $rates);
    }

    // --- getRatesForDate: future with no snapshot at all throws ---

    public function testGetRatesForDate_futureWithNoSnapshotAtAll_throwsRuntimeException(): void
    {
        $futureDate = CarbonImmutable::now()->addMonth();

        $this->snapshotRepository->method('findExactSnapshot')->willReturn(null);
        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/future date/');

        $this->resolver->getRatesForDate($futureDate);
    }

    // --- resolveSnapshotsForDates ---

    public function testResolveSnapshotsForDates_emptyInput_returnsEmptyArray(): void
    {
        $result = $this->resolver->resolveSnapshotsForDates([]);

        self::assertSame([], $result);
        $this->snapshotRepository->expects(self::never())->method('findClosestSnapshot');
    }

    public function testResolveSnapshotsForDates_sameCalendarDayTwice_queriedOnlyOnce(): void
    {
        $dateA = new \DateTimeImmutable('2026-03-10 08:00:00');
        $dateB = new \DateTimeImmutable('2026-03-10 20:00:00');
        $snapshot = $this->createSnapshotStub(CarbonImmutable::parse('2026-03-10'));

        $this->snapshotRepository
            ->expects(self::once())
            ->method('findClosestSnapshot')
            ->willReturn($snapshot);

        $result = $this->resolver->resolveSnapshotsForDates([$dateA, $dateB]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('2026-03-10', $result);
        self::assertSame($snapshot, $result['2026-03-10']);
    }

    public function testResolveSnapshotsForDates_multipleDatesAllInDb_returnsAll(): void
    {
        $dates = [
            new \DateTimeImmutable('2026-03-10'),
            new \DateTimeImmutable('2026-03-11'),
        ];
        $snapshot1 = $this->createSnapshotStub(CarbonImmutable::parse('2026-03-10'));
        $snapshot2 = $this->createSnapshotStub(CarbonImmutable::parse('2026-03-11'));

        $this->snapshotRepository
            ->method('findClosestSnapshot')
            ->willReturnOnConsecutiveCalls($snapshot1, $snapshot2);

        $result = $this->resolver->resolveSnapshotsForDates($dates);

        self::assertCount(2, $result);
        self::assertSame($snapshot1, $result['2026-03-10']);
        self::assertSame($snapshot2, $result['2026-03-11']);
    }

    public function testResolveSnapshotsForDates_oneMissingDate_fetchesFromFixer(): void
    {
        $dates = [
            new \DateTimeImmutable('2026-03-10'),
            new \DateTimeImmutable('2026-03-11'),
        ];
        $snapshot1 = $this->createSnapshotStub(CarbonImmutable::parse('2026-03-10'));

        $this->snapshotRepository
            ->method('findClosestSnapshot')
            ->willReturnOnConsecutiveCalls($snapshot1, null);
        $this->snapshotRepository->method('findOneBy')->willReturn(null);

        $this->fixerService
            ->expects(self::once())
            ->method('getHistorical')
            ->willReturn(self::FIXER_RATES);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $result = $this->resolver->resolveSnapshotsForDates($dates);

        self::assertCount(2, $result);
        self::assertSame($snapshot1, $result['2026-03-10']);
        self::assertInstanceOf(ExchangeRateSnapshot::class, $result['2026-03-11']);
    }

    public function testResolveSnapshotsForDates_futureDateWithNoSnapshot_throwsRuntimeException(): void
    {
        $futureDate = CarbonImmutable::now()->addDays(5);

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/future date/');

        $this->resolver->resolveSnapshotsForDates([$futureDate]);
    }

    public function testResolveSnapshotsForDates_keysAreNormalisedToStartOfDay(): void
    {
        $dateWithTime = new \DateTimeImmutable('2026-03-10 15:30:45');
        $snapshot = $this->createSnapshotStub(CarbonImmutable::parse('2026-03-10'));

        $this->snapshotRepository->method('findClosestSnapshot')->willReturn($snapshot);

        $result = $this->resolver->resolveSnapshotsForDates([$dateWithTime]);

        self::assertArrayHasKey('2026-03-10', $result);
    }

    private function createSnapshotStub(CarbonImmutable $date): ExchangeRateSnapshot
    {
        $snapshot = new ExchangeRateSnapshot();
        $snapshot->setEffectiveAt($date);
        $snapshot->setUsdPerEur('1.1');
        $snapshot->setHufPerEur('380');
        $snapshot->setUahPerEur('40');

        return $snapshot;
    }

    private function buildUniqueConstraintViolationException(): UniqueConstraintViolationException
    {
        return $this->createMock(UniqueConstraintViolationException::class);
    }
}
