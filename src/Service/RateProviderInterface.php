<?php

namespace App\Service;

use Carbon\CarbonInterface;

interface RateProviderInterface
{
    /**
     * Get the latest exchange rates.
     *
     * @return array
     */
    public function getLatest(): array;

    /**
     * Get exchange rates on a given date.
     *
     * @param CarbonInterface $date
     * @return array|null
     */
    public function getHistorical(CarbonInterface $date): ?array;

    /**
     * Get exchange rates, either the latest or historical based on the given date.
     *
     * @param CarbonInterface|null $date
     * @return array
     */
    public function getRates(?CarbonInterface $date = null): array;
}
