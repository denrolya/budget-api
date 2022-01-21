<?php

namespace App\DataProvider;

use ApiPlatform\Core\DataProvider\DenormalizedIdentifiersAwareItemDataProviderInterface;
use ApiPlatform\Core\DataProvider\RestrictedDataProviderInterface;
use App\Entity\ExchangeRates;
use App\Service\FixerService;
use Carbon\CarbonImmutable;

final class ExchangeRatesProvider implements DenormalizedIdentifiersAwareItemDataProviderInterface, RestrictedDataProviderInterface
{
    public function __construct(
        private FixerService $fixer,
    )
    {
    }

    public function getItem(string $resourceClass, $id, string $operationName = null, array $context = []): ?object
    {
        $date = CarbonImmutable::createFromFormat('d-m-Y', $id['dateString']);
        $rates = $this->fixer->getHistorical($date);

        return new ExchangeRates($date, $rates);
    }

    public function supports(string $resourceClass, string $operationName = null, array $context = []): bool
    {
        return $resourceClass === ExchangeRates::class;
    }
}
