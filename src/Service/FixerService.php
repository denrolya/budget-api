<?php

namespace App\Service;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JetBrains\PhpStorm\ArrayShape;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TODO: After 00:00 in EET it is impossible to fetch rates, cause fixer's timezone is couple of hours ago
 */
class FixerService extends BaseExchangeRatesProvider
{
    private string $apiKey;

    private Security $security;

    public function __construct(
        HttpClientInterface $fixerClient,
        string $fixerApiKey,
        CacheInterface $cache,
        Security $security,
        array $allowedCurrencies,
        string $baseCurrency
    ) {
        parent::__construct($fixerClient, $cache, $allowedCurrencies, $baseCurrency);

        $this->apiKey = $fixerApiKey;
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
        if (!in_array($fromCurrency, $this->allowedCurrencies, true)) {
            return [];
        }

        $values = [];
        foreach ($this->allowedCurrencies as $currency) {
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
     * Get the latest exchange rates and store them in cache.
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function getLatest(): array
    {
        $now = CarbonImmutable::now();
        $dateString = $now->toDateString();

        return $this->cache->get( "fixer.$dateString", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_EXPIRY_SECONDS);

            return $this->fetchRates( '/latest');
        });
    }

    /**
     * Get exchange rates on a given date.
     *
     * @param CarbonInterface $date
     * @return array|null
     * @throws InvalidArgumentException
     */
    public function getHistorical(CarbonInterface $date): ?array
    {
        $dateString = $date->toDateString();

        return $this->cache->get("fixer.$dateString", function (ItemInterface $item) use ($dateString) {
            $item->expiresAfter(self::CACHE_EXPIRY_SECONDS);

            return $this->fetchRates('/' . $dateString);
        });
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

    /**
     * Fetch exchange rates from the external API.
     *
     * @param string $endpoint
     * @return array
     * @throws JsonException
     */
    protected function fetchRates(string $endpoint): array
    {
        $queryParams = $this->getRequestParams();
        try {
            $response = $this->client->request('GET', $endpoint, [
                'query' => $queryParams,
            ])->getContent();

            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            return $data['rates'] ?? [];
        } catch (HttpExceptionInterface | TransportExceptionInterface $e) {
            throw new RuntimeException('Failed to fetch rates from the Fixer API: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }


    #[ArrayShape(['access_key' => "string", 'base' => "string", 'symbols' => "string"])]
    private function getRequestParams(): array
    {
        return [
            'access_key' => $this->apiKey,
            'base' => $this->baseCurrency,
            'symbols' => implode(',', $this->allowedCurrencies),
        ];
    }
}
