<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExchangeRateSnapshot;
use App\Repository\ExchangeRateSnapshotRepository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use RuntimeException;
use Throwable;

readonly class ExchangeRateSnapshotResolver
{
    public function __construct(
        private ExchangeRateSnapshotRepository $snapshotRepository,
        private EntityManagerInterface $entityManager,
        private FixerService $fixerService,
    ) {
    }

    /**
     * Single-date resolver: checks DB first, fetches from Fixer only when needed.
     * Persists every new fetch so the same date is never fetched again.
     *
     * @throws NonUniqueResultException
     */
    public function getClosestOrFetch(DateTimeInterface $date): ExchangeRateSnapshot
    {
        $carbon = CarbonImmutable::instance($date)->startOfDay();

        $snapshot = $this->snapshotRepository->findClosestSnapshot($carbon);
        if ($snapshot instanceof ExchangeRateSnapshot) {
            return $snapshot;
        }

        if ($carbon->isFuture()) {
            throw new RuntimeException(\sprintf('No snapshot available for future date %s.', $carbon->toDateString()));
        }

        return $this->fetchAndPersistSnapshot($carbon);
    }

    /**
     * Bulk resolver: accepts many dates and returns a map dateString => snapshot.
     * Fixer is called at most once per missing date.
     *
     * @param DateTimeInterface[] $dates
     *
     * @return array<string, ExchangeRateSnapshot>
     *
     * @throws NonUniqueResultException
     */
    public function resolveSnapshotsForDates(array $dates): array
    {
        $result = [];

        $uniqueDates = [];
        foreach ($dates as $date) {
            $day = CarbonImmutable::instance($date)->startOfDay();
            $uniqueDates[$day->toDateString()] = $day;
        }

        foreach ($uniqueDates as $key => $day) {
            $snapshot = $this->snapshotRepository->findClosestSnapshot($day);
            if ($snapshot instanceof ExchangeRateSnapshot) {
                $result[$key] = $snapshot;
            }
        }

        foreach ($uniqueDates as $key => $day) {
            if (isset($result[$key])) {
                continue;
            }

            if ($day->isFuture()) {
                throw new RuntimeException(\sprintf('No snapshot available for future date %s.', $day->toDateString()));
            }

            $result[$key] = $this->fetchAndPersistSnapshot($day);
        }

        return $result;
    }

    /**
     * Returns the rates array (currency => float) for a given date.
     * Uses DB snapshot when available; calls Fixer only when no snapshot exists.
     *
     * @return array<string, float>
     *
     * @throws NonUniqueResultException
     * @throws RuntimeException
     */
    public function getRatesForDate(DateTimeInterface $date): array
    {
        $carbon = CarbonImmutable::instance($date)->startOfDay();

        $existingSnapshot = $this->snapshotRepository->findExactSnapshot($carbon);
        if ($existingSnapshot instanceof ExchangeRateSnapshot) {
            return $this->snapshotToRatesArray($existingSnapshot);
        }

        if ($carbon->isFuture()) {
            $closestSnapshot = $this->snapshotRepository->findClosestSnapshot($carbon);
            if ($closestSnapshot instanceof ExchangeRateSnapshot) {
                return $this->snapshotToRatesArray($closestSnapshot);
            }

            throw new RuntimeException(\sprintf('No snapshot available for future date %s.', $carbon->toDateString()));
        }

        $snapshot = $this->fetchAndPersistSnapshot($carbon);

        return $this->snapshotToRatesArray($snapshot);
    }

    /**
     * Fetches rates from Fixer and persists as a new snapshot.
     * Re-checks DB first to handle race conditions.
     */
    private function fetchAndPersistSnapshot(CarbonImmutable $day): ExchangeRateSnapshot
    {
        $existing = $this->snapshotRepository->findOneBy(['effectiveAt' => $day]);
        if ($existing instanceof ExchangeRateSnapshot) {
            return $existing;
        }

        $isToday = $day->isToday();

        try {
            $rates = $isToday
                ? $this->fixerService->getLatest()
                : $this->fixerService->getHistorical($day);
        } catch (Throwable $exception) {
            throw new RuntimeException(\sprintf('Failed to fetch rates for %s: %s', $day->toDateString(), $exception->getMessage()), 0, $exception);
        }

        if ([] === $rates || null === $rates) {
            throw new RuntimeException(\sprintf('No rates returned from Fixer for %s.', $day->toDateString()));
        }

        $snapshot = new ExchangeRateSnapshot();
        $snapshot->setEffectiveAt($day);

        $this->applyRatesToSnapshot($snapshot, $rates);

        try {
            $this->entityManager->persist($snapshot);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $existing = $this->snapshotRepository->findOneBy(['effectiveAt' => $day]);
            if ($existing instanceof ExchangeRateSnapshot) {
                return $existing;
            }

            throw new RuntimeException(\sprintf('Race condition while creating snapshot for %s.', $day->toDateString()));
        }

        return $snapshot;
    }

    /** @param array<string, float> $rates */
    private function applyRatesToSnapshot(ExchangeRateSnapshot $snapshot, array $rates): void
    {
        if (isset($rates['USD'])) {
            $snapshot->setUsdPerEur((string) $rates['USD']);
        }
        if (isset($rates['HUF'])) {
            $snapshot->setHufPerEur((string) $rates['HUF']);
        }
        if (isset($rates['UAH'])) {
            $snapshot->setUahPerEur((string) $rates['UAH']);
        }
        if (isset($rates['BTC'])) {
            $snapshot->setEurPerBtc((string) (1.0 / $rates['BTC']));
        }
        if (isset($rates['ETH'])) {
            $snapshot->setEurPerEth((string) (1.0 / $rates['ETH']));
        }
    }

    /**
     * @return array<string, float>
     */
    private function snapshotToRatesArray(ExchangeRateSnapshot $snapshot): array
    {
        $rates = ['EUR' => 1.0];

        $usd = $snapshot->getUsdPerEurFloat();
        if (null !== $usd) {
            $rates['USD'] = $usd;
        }

        $huf = $snapshot->getHufPerEurFloat();
        if (null !== $huf) {
            $rates['HUF'] = $huf;
        }

        $uah = $snapshot->getUahPerEurFloat();
        if (null !== $uah) {
            $rates['UAH'] = $uah;
        }

        $btcPerEur = $snapshot->getBtcPerEurFloat();
        if (null !== $btcPerEur) {
            $rates['BTC'] = $btcPerEur;
        }

        $ethPerEur = $snapshot->getEthPerEurFloat();
        if (null !== $ethPerEur) {
            $rates['ETH'] = $ethPerEur;
        }

        return $rates;
    }
}
