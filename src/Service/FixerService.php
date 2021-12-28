<?php

namespace App\Service;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FixerService
{
    private const BASE_URL = 'https://data.fixer.io/api/';
    private const MONTH_IN_SECONDS = 2678400;
    private const FIAT_CURRENCIES = ['EUR', 'USD', 'HUF', 'UAH'];

    private string $apiKey;

    private Security $security;

    private HttpClientInterface $client;

    private CacheInterface $cache;

    public function __construct(HttpClientInterface $client, string $fixerApiKey, CacheInterface $cache, Security $security)
    {
        $this->apiKey = $fixerApiKey;
        $this->client = $client;
        $this->cache = $cache;
        $this->security = $security;
    }

    /**
     * @param float $amount
     * @param string $currencyCode
     * @param CarbonInterface|null $executionDate
     * @return float
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function convertToBaseCurrency(float $amount, string $currencyCode, ?CarbonInterface $executionDate = null): float
    {
        return $this->convertTo($amount, $currencyCode, $this->security->getUser()->getBaseCurrency(), $executionDate->copy());
    }

    /**
     * Generates array of converted values to all base fiat currencies
     *
     * @param float $amount
     * @param string $from
     * @param CarbonInterface|null $executionDate
     * @return array
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function convert(float $amount, string $from, ?CarbonInterface $executionDate = null): array
    {
        if(!in_array($from, self::FIAT_CURRENCIES)) {
            return [];
        }

        $values = [];
        foreach(self::FIAT_CURRENCIES as $currency) {
            $values[$currency] = $this->convertTo(
                $amount,
                $from,
                $currency,
                $executionDate
            );
        }

        return $values;
    }

    /**
     * Convert amount from one currency to another
     *
     * @param float $amount
     * @param string $from
     * @param string $to
     * @param CarbonInterface|null $executionDate
     * @return float
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function convertTo(float $amount, string $from, string $to, ?CarbonInterface $executionDate = null): float
    {
        if(!$this->currencyExists($from)) {
            throw new \InvalidArgumentException("Invalid currency code passed as `from` parameter: $from. ");
        }

        if(!$this->currencyExists($to)) {
            throw new \InvalidArgumentException("Invalid currency code passed as `to` parameter: $to. ");
        }

        $rates = $this->getRates($executionDate ? $executionDate->copy() : null);

        return $amount / $rates[$from] * $rates[$to];
    }

    /**
     * Get the latest exchange rates and store them in cache
     *
     * @return array
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function getLatest(): array
    {
        $now = Carbon::now();
        $dateString = $now->toDateString();
        if(!$this->cache->has("fixer.$dateString")) {
            $query = self::BASE_URL . 'latest?' . http_build_query($this->getRequestParams());
            $response = $this->client->get($query)->getBody()->getContents();

            $this->cache->set(
                "fixer.$dateString",
                json_decode($response, true)['rates'],
                self::MONTH_IN_SECONDS,
            );
        }

        return $this->cache->get("fixer.$dateString");
    }

    /**
     * Get exchange rates on a given date
     *
     * @param CarbonInterface $date
     * @return array|null
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function getHistorical(CarbonInterface $date): ?array
    {
        $dateString = $date->toDateString();

        if(!$this->cache->has("fixer.$dateString")) {
            $query = self::BASE_URL . $dateString . '?' . http_build_query($this->getRequestParams());
            $response = $this->client->get($query)->getBody()->getContents();

            $this->cache->set(
                "fixer.$dateString",
                json_decode($response, true)['rates'],
                self::MONTH_IN_SECONDS,
            );
        }

        return $this->cache->get("fixer.$dateString");
    }

    /**
     * @param CarbonInterface|null $date
     * @return array
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function getRates(?CarbonInterface $date = null): array
    {
        return (!$date || $date->month === Carbon::now()->month)
            ? $this->getLatest()
            : $this->getHistorical($date->endOfMonth());
    }

    /**
     * Check if currency is supported by Fixer
     *
     * @param string $currencyCode
     * @return bool
     * @throws GuzzleException
     * @throws InvalidArgumentException
     */
    public function currencyExists(string $currencyCode): bool
    {
        $rates = $this->getLatest();

        return array_key_exists($currencyCode, $rates);
    }

    /**
     * @return array
     */
    private function getRequestParams(): array
    {
        return [
            'access_key' => $this->apiKey,
            'base' => 'EUR',
            'symbols' => 'EUR, USD, HUF, UAH, BTC, ETH',
        ];
    }
}
