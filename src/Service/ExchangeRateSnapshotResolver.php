<?php

namespace App\Service;

use DateTimeInterface;
use App\Entity\ExchangeRateSnapshot;
use App\Repository\ExchangeRateSnapshotRepository;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;

readonly class ExchangeRateSnapshotResolver
{
    public function __construct(
        private ExchangeRateSnapshotRepository $snapshotRepo,
        private EntityManagerInterface $em,
        private FixerService $fixerService,
    ) {
    }

    /**
     * Single-date resolver with "today" auto-fetch.
     * @throws NonUniqueResultException
     */
    public function getClosestOrFetch(DateTimeInterface $date): ExchangeRateSnapshot
    {
        $carbon = CarbonImmutable::instance($date)->startOfDay();

        $snapshot = $this->snapshotRepo->findClosestSnapshot($carbon);
        if ($snapshot instanceof ExchangeRateSnapshot) {
            return $snapshot;
        }

        // Past date with no snapshot -> data hole, fail
        if ($carbon->isPast() && !$carbon->isToday()) {
            throw new \RuntimeException(
                sprintf('No exchange rate snapshot available for %s or any earlier date.', $carbon->toDateString())
            );
        }

        // Future date: do not auto-fetch
        if ($carbon->isFuture()) {
            throw new \RuntimeException(
                sprintf('No snapshot available for future date %s.', $carbon->toDateString())
            );
        }

        // Today and no snapshot -> try to fetch and persist once
        return $this->fetchAndStoreTodaySnapshot($carbon);
    }

    /**
     * Bulk resolver: accepts many dates and returns a map dateString => snapshot.
     * Fixer is called at most once for "today" in this request.
     * @throws NonUniqueResultException
     */
    public function resolveSnapshotsForDates(array $dates): array
    {
        $result = [];
        $today = CarbonImmutable::today();

        // Normalize to unique day strings
        $uniqueDates = [];
        foreach ($dates as $date) {
            if (!$date instanceof DateTimeInterface) {
                continue;
            }
            $day = CarbonImmutable::instance($date)->startOfDay();
            $uniqueDates[$day->toDateString()] = $day;
        }

        // First, try DB-only for all dates
        foreach ($uniqueDates as $key => $day) {
            $snapshot = $this->snapshotRepo->findClosestSnapshot($day);
            if ($snapshot instanceof ExchangeRateSnapshot) {
                $result[$key] = $snapshot;
            }
        }

        // Handle missing dates
        foreach ($uniqueDates as $key => $day) {
            if (isset($result[$key])) {
                continue; // already resolved
            }

            if ($day->isPast() && !$day->isToday()) {
                throw new \RuntimeException(
                    sprintf('No exchange rate snapshot available for %s or any earlier date.', $day->toDateString())
                );
            }

            if ($day->isFuture()) {
                throw new \RuntimeException(
                    sprintf('No snapshot available for future date %s.', $day->toDateString())
                );
            }

            // Only "today" left here; ensure we have a snapshot for today
            $todaySnapshot = $this->fetchAndStoreTodaySnapshot($today);

            // Today can cover only today, not arbitrary earlier date.
            if ($day->isToday()) {
                $result[$key] = $todaySnapshot;
            }
        }

        return $result;
    }

    private function fetchAndStoreTodaySnapshot(CarbonImmutable $day): ExchangeRateSnapshot
    {
        // Re-check DB in case another process already created it
        $existing = $this->snapshotRepo->findOneBy(['effectiveAt' => $day]);
        if ($existing instanceof ExchangeRateSnapshot) {
            return $existing;
        }

        $rates = $this->fixerService->getLatest();
        if (empty($rates)) {
            throw new \RuntimeException('Failed to fetch latest rates for today.');
        }

        $snapshot = new ExchangeRateSnapshot();
        $snapshot->setEffectiveAt($day);

        // Map Fixer response -> snapshot fields
        if (isset($rates['USD'])) {
            $snapshot->setUsdPerEur((string)$rates['USD']);
        }
        if (isset($rates['HUF'])) {
            $snapshot->setHufPerEur((string)$rates['HUF']);
        }
        if (isset($rates['UAH'])) {
            $snapshot->setUahPerEur((string)$rates['UAH']);
        }

        // BTC / ETH can stay null or be filled from another provider elsewhere

        try {
            $this->em->persist($snapshot);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Another process won the race -> read again
            $existing = $this->snapshotRepo->findOneBy(['effectiveAt' => $day]);
            if ($existing instanceof ExchangeRateSnapshot) {
                return $existing;
            }
            throw new \RuntimeException('Failed to create snapshot for today due to a race condition, and it could not be read back from the database.');
        }

        return $snapshot;
    }
}