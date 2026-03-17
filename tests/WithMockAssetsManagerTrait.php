<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\AssetsManager;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

trait WithMockAssetsManagerTrait
{
    private const EXCHANGE_RATES = [
        'USD' => 1.2,
        'EUR' => 1.0,
        'HUF' => 300.0,
        'UAH' => 30.0,
        'BTC' => 0.0001,
    ];

    protected MockObject $mockAssetsManager;

    protected function createAssetsManagerMock(): MockObject
    {
        /** @var MockObject&AssetsManager $mock */
        $mock = $this->getMockBuilder(AssetsManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['convert'])
            ->getMock();

        $mock->method('convert')
            ->willReturnCallback(static function ($entity) {
                /** @phpstan-ignore-next-line */
                $amount = (float) $entity->{'get' . ucfirst($entity->getValuableField())}();
                $fromCurrency = strtoupper($entity->getCurrency());

                if (!isset(self::EXCHANGE_RATES[$fromCurrency])) {
                    throw new RuntimeException("Unsupported test currency: {$fromCurrency}");
                }

                $result = [];
                foreach (self::EXCHANGE_RATES as $currency => $rate) {
                    $result[$currency] = $amount / self::EXCHANGE_RATES[$fromCurrency] * $rate;
                }

                return $result;
            });

        return $mock;
    }
}
