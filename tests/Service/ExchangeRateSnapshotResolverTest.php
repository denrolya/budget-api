<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ExchangeRateSnapshot;
use App\Repository\ExchangeRateSnapshotRepository;
use App\Service\ExchangeRateSnapshotResolver;
use App\Service\FixerService;
use Carbon\CarbonImmutable;
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

    private function createSnapshotStub(CarbonImmutable $date): ExchangeRateSnapshot
    {
        $snapshot = new ExchangeRateSnapshot();
        $snapshot->setEffectiveAt($date);
        $snapshot->setUsdPerEur('1.1');
        $snapshot->setHufPerEur('380');
        $snapshot->setUahPerEur('40');

        return $snapshot;
    }
}
