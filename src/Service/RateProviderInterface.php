<?php

declare(strict_types=1);

namespace App\Service;

use Carbon\CarbonInterface;

interface RateProviderInterface
{
    /**
     * Get the latest exchange rates.
     */
    public function getLatest(): array;

    /**
     * Get exchange rates on a given date.
     */
    public function getHistorical(CarbonInterface $date): ?array;

    /**
     * Get exchange rates, either the latest or historical based on the given date.
     */
    public function getRates(?CarbonInterface $date = null): array;
}
