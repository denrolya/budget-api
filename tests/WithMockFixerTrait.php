<?php

namespace App\Tests;

use App\Service\FixerService;
use Carbon\CarbonInterface;

trait WithMockFixerTrait
{
    private const EXCHANGE_RATES = [
        'USD' => 1.2,
        'EUR' => 1.0,
        'HUF' => 300.0,
        'UAH' => 30.0,
        'BTC' => 0.0001,
    ];

    protected $mockFixerService;

    protected function createFixerServiceMock(callable $callback = null)
    {
        $mockFixerService = $this->getMockBuilder(FixerService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['convert', 'getLatest'])
            ->getMock();

        $defaultCallback = static function (
            float $amount,
            string $fromCurrency,
            ?CarbonInterface $executionDate = null
        ) {
            $convertedValues = [];
            foreach (self::EXCHANGE_RATES as $currency => $rate) {
                $convertedValues[$currency] = $amount / self::EXCHANGE_RATES[$fromCurrency] * $rate;
            }

            return $convertedValues;
        };

        $mockFixerService
            ->method('convert')
            ->willReturnCallback($callback ?? $defaultCallback);

        $mockFixerService->method('getLatest')->willReturn(self::EXCHANGE_RATES);

        return $mockFixerService;
    }
}
