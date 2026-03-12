<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FixerService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FixerServiceTest extends TestCase
{
    private const RATES = ['EUR' => 1.0, 'USD' => 1.1, 'UAH' => 40.0];

    /**
     * @return FixerService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeService(): FixerService
    {
        /** @var FixerService&\PHPUnit\Framework\MockObject\MockObject $service */
        $service = $this->getMockBuilder(FixerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLatest', 'getHistorical'])
            ->getMock();

        return $service;
    }

    public function testGetRatesWithNullDateCallsGetLatest(): void
    {
        $service = $this->makeService();
        $service->expects(self::once())->method('getLatest')->willReturn(self::RATES);
        $service->expects(self::never())->method('getHistorical');

        self::assertSame(self::RATES, $service->getRates(null));
    }

    public function testGetRatesWithTodayCallsGetLatest(): void
    {
        $service = $this->makeService();
        $service->expects(self::once())->method('getLatest')->willReturn(self::RATES);
        $service->expects(self::never())->method('getHistorical');

        self::assertSame(self::RATES, $service->getRates(CarbonImmutable::now()));
    }

    public function testGetRatesWithYesterdayCallsGetHistorical(): void
    {
        $yesterday = CarbonImmutable::yesterday();

        $service = $this->makeService();
        $service->expects(self::never())->method('getLatest');
        $service->expects(self::once())->method('getHistorical')->with($yesterday)->willReturn(self::RATES);

        self::assertSame(self::RATES, $service->getRates($yesterday));
    }

    /**
     * Regression: old code compared only `->day` (day-of-month), so Jan 15 and Mar 15 were
     * treated as "today" when today is any 15th. Must route to getHistorical instead.
     */
    public function testGetRatesSameDayOfMonthDifferentMonthCallsGetHistorical(): void
    {
        // Pick a date that shares the day-of-month with today but is in a prior month.
        $today = CarbonImmutable::now();
        $sameDayLastMonth = $today->subMonth();

        // Skip if today is the 1st and subMonth lands on the same month (edge case with months of different lengths)
        if ($sameDayLastMonth->month === $today->month) {
            $this->markTestSkipped('subMonth() did not produce a prior month on this date.');
        }

        $service = $this->makeService();
        $service->expects(self::never())->method('getLatest');
        $service->expects(self::once())->method('getHistorical')->willReturn(self::RATES);

        $service->getRates($sameDayLastMonth);
    }

    /**
     * A future date also shares no calendar identity with "today" and must call getHistorical.
     */
    public function testGetRatesWithFutureDateCallsGetHistorical(): void
    {
        $future = CarbonImmutable::now()->addMonth();

        $service = $this->makeService();
        $service->expects(self::never())->method('getLatest');
        $service->expects(self::once())->method('getHistorical')->willReturn(self::RATES);

        $service->getRates($future);
    }
}
