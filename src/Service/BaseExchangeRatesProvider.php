<?php

namespace App\Service;

use Carbon\CarbonInterface;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class BaseExchangeRatesProvider
{
    protected const CACHE_EXPIRY_SECONDS = 2678400; // 31 days

    protected HttpClientInterface $client;

    protected CacheInterface $cache;

    protected array $allowedCurrencies;

    protected string $baseCurrency;

    public function __construct(
        HttpClientInterface $client,
        CacheInterface $cache,
        array $allowedCurrencies,
        string $baseCurrency,
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->allowedCurrencies = $allowedCurrencies;
        $this->baseCurrency = $baseCurrency;
    }

    /**
     * Get the latest exchange rates and store them in cache.
     *
     * This method must be implemented by child classes.
     *
     * @return array
     * @throws InvalidArgumentException
     */
    abstract public function getLatest(): array;

    /**
     * Optional: Get historical exchange rates.
     * Child classes can override this if they support fetching historical rates.
     *
     * @param CarbonInterface $date
     * @return array|null
     */
    public function getHistorical(CarbonInterface $date): ?array
    {
        throw new RuntimeException('This service does not support fetching historical rates.');
    }
}
