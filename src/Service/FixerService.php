<?php

declare(strict_types=1);

namespace App\Service;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use JsonException;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        string $baseCurrency,
    ) {
        parent::__construct($fixerClient, $cache, $allowedCurrencies, $baseCurrency);

        $this->apiKey = $fixerApiKey;
        $this->security = $security;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function convertToBaseCurrency(
        float $amount,
        string $currencyCode,
        ?CarbonInterface $executionDate = null,
    ): float {
        $user = $this->security->getUser();
        \assert($user instanceof \App\Entity\User || null === $user);

        return $this->convertTo(
            $amount,
            $currencyCode,
            $user?->getBaseCurrency(),
            $executionDate?->copy(),
        );
    }

    /**
     * Generates array of converted values to all base fiat currencies
     *
     * @throws InvalidArgumentException
     */
    public function convert(float $amount, string $fromCurrency, ?CarbonInterface $executionDate = null): array
    {
        if (!\in_array($fromCurrency, $this->allowedCurrencies, true)) {
            return [];
        }

        $values = [];
        foreach ($this->allowedCurrencies as $currency) {
            $values[$currency] = $this->convertTo(
                $amount,
                $fromCurrency,
                $currency,
                $executionDate,
            );
        }

        return $values;
    }

    /**
     * Convert amount from one currency to another
     *
     * @throws InvalidArgumentException
     */
    public function convertTo(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        ?CarbonInterface $executionDate = null,
    ): float {
        if (!$this->currencyExists($fromCurrency)) {
            throw new \InvalidArgumentException("Invalid currency code passed as `fromCurrency` parameter: $fromCurrency. ");
        }

        if (!$this->currencyExists($toCurrency)) {
            throw new \InvalidArgumentException("Invalid currency code passed as `toCurrency` parameter: $toCurrency. ");
        }

        $rates = $this->getRates($executionDate?->copy());

        return $amount / $rates[$fromCurrency] * $rates[$toCurrency];
    }

    /**
     * Get the latest exchange rates and store them in cache.
     *
     * @throws InvalidArgumentException
     */
    public function getLatest(): array
    {
        $now = CarbonImmutable::now();
        $dateString = $now->toDateString();

        return $this->cache->get("fixer.$dateString", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_EXPIRY_SECONDS);

            return $this->fetchRates('/latest');
        });
    }

    /**
     * Get exchange rates on a given date.
     *
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
     * @throws InvalidArgumentException
     */
    public function getRates(?CarbonInterface $date = null): array
    {
        return (!$date || $date->isSameDay(CarbonImmutable::now()))
            ? $this->getLatest()
            : $this->getHistorical($date);
    }

    /**
     * Check if currency is supported by Fixer
     *
     * @throws InvalidArgumentException
     */
    public function currencyExists(string $currencyCode): bool
    {
        $rates = $this->getLatest();

        return \array_key_exists($currencyCode, $rates);
    }

    /**
     * Fetch exchange rates from the external API.
     *
     * @throws JsonException
     */
    protected function fetchRates(string $endpoint): array
    {
        $queryParams = $this->getRequestParams();
        try {
            $response = $this->client->request('GET', $endpoint, [
                'query' => $queryParams,
            ])->getContent();

            $decoded = json_decode($response, true, 512, \JSON_THROW_ON_ERROR);
            assert(\is_array($decoded));

            return \is_array($decoded['rates'] ?? null) ? $decoded['rates'] : [];
        } catch (HttpExceptionInterface|TransportExceptionInterface $e) {
            throw new RuntimeException('Failed to fetch rates from the Fixer API: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /** @return array{access_key: string, base: string, symbols: string} */
    private function getRequestParams(): array
    {
        return [
            'access_key' => $this->apiKey,
            'base' => $this->baseCurrency,
            'symbols' => implode(',', $this->allowedCurrencies),
        ];
    }
}
