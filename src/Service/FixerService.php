<?php

namespace App\Service;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TODO: After 00:00 in Ukraine it is impossible to fetch rates, cause fixer's timezone is couple of hours ago
 */
class FixerService
{
    private const MONTH_IN_SECONDS = 2678400;
    private const AVAILABLE_CURRENCIES = ['EUR', 'USD', 'HUF', 'UAH', 'BTC'];

    private Security $security;

    private HttpClientInterface $client;

    private CacheInterface $cache;

    private string $apiKey;

    public function __construct(
        HttpClientInterface $fixerClient,
        string $fixerApiKey,
        CacheInterface $cache,
        Security $security
    ) {
        $this->apiKey = $fixerApiKey;
        $this->client = $fixerClient;
        $this->cache = $cache;
        $this->security = $security;
    }

    /**
     * @param float $amount
     * @param string $currencyCode
     * @param CarbonInterface|null $executionDate
     * @return float
     * @throws InvalidArgumentException
     */
    public function convertToBaseCurrency(
        float $amount,
        string $currencyCode,
        ?CarbonInterface $executionDate = null
    ): float {
        return $this->convertTo(
            $amount,
            $currencyCode,
            $this->security->getUser()->getBaseCurrency(),
            $executionDate->copy()
        );
    }

    /**
     * Generates array of converted values to all base fiat currencies
     *
     * @param float $amount
     * @param string $fromCurrency
     * @param CarbonInterface|null $executionDate
     * @return array
     * @throws InvalidArgumentException
     */
    public function convert(float $amount, string $fromCurrency, ?CarbonInterface $executionDate = null): array
    {
        if (!in_array($fromCurrency, self::AVAILABLE_CURRENCIES)) {
            return [];
        }

        $values = [];
        foreach (self::AVAILABLE_CURRENCIES as $currency) {
            $values[$currency] = $this->convertTo(
                $amount,
                $fromCurrency,
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
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param CarbonInterface|null $executionDate
     * @return float
     * @throws InvalidArgumentException
     */
    public function convertTo(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        ?CarbonInterface $executionDate = null
    ): float {
        if (!$this->currencyExists($fromCurrency)) {
            throw new \InvalidArgumentException(
                "Invalid currency code passed as `fromCurrency` parameter: $fromCurrency. "
            );
        }

        if (!$this->currencyExists($toCurrency)) {
            throw new \InvalidArgumentException(
                "Invalid currency code passed as `toCurrency` parameter: $toCurrency. "
            );
        }

        $rates = $this->getRates($executionDate?->copy());

        return $amount / $rates[$fromCurrency] * $rates[$toCurrency];
    }

    /**
     * Get the latest exchange rates and store them in cache
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function getLatest(): array
    {
        $now = CarbonImmutable::now();
        $dateString = $now->toDateString();
        $requestParams = $this->getRequestParams();
        $client = $this->client;

        return $this->cache->get(
            "fixer.$dateString",
            static function (ItemInterface $item) use ($requestParams, $client) {
                $item->expiresAfter(self::MONTH_IN_SECONDS);
                $response = $client->request('GET', '/latest', [
                    'query' => $requestParams,
                ])->getContent();

                return json_decode($response, true, 512, JSON_THROW_ON_ERROR)['rates'];
            }
        );
    }

    /**
     * Get exchange rates on a given date
     *
     * @param CarbonInterface $date
     * @return array|null
     * @throws InvalidArgumentException
     */
    public function getHistorical(CarbonInterface $date): ?array
    {
        $dateString = $date->toDateString();
        $requestParams = $this->getRequestParams();
        $client = $this->client;

        return $this->cache->get(
            "fixer.$dateString",
            static function (ItemInterface $item) use ($requestParams, $client, $dateString) {
                $item->expiresAfter(self::MONTH_IN_SECONDS);
                $response = $client->request('GET', '/'.$dateString, [
                    'query' => $requestParams,
                ])->getContent();

                return json_decode($response, true, 512, JSON_THROW_ON_ERROR)['rates'];
            }
        );
    }

    /**
     * @param CarbonInterface|null $date
     * @return array
     * @throws InvalidArgumentException
     */
    public function getRates(?CarbonInterface $date = null): array
    {
        return (!$date || $date->day === CarbonImmutable::now()->day)
            ? $this->getLatest()
            : $this->getHistorical($date);
    }

    /**
     * Check if currency is supported by Fixer
     *
     * @param string $currencyCode
     * @return bool
     * @throws InvalidArgumentException
     */
    public function currencyExists(string $currencyCode): bool
    {
        $rates = $this->getLatest();

        return array_key_exists($currencyCode, $rates);
    }

    #[ArrayShape(['access_key' => "string", 'base' => "string", 'symbols' => "string"])]
    private function getRequestParams(): array
    {
        return [
            'access_key' => $this->apiKey,
            'base' => 'EUR',
            'symbols' => implode(',', self::AVAILABLE_CURRENCIES),
        ];
    }
}
