<?php

namespace App\Service;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Exception;
use RuntimeException;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class WiseService extends BaseExchangeRatesProvider
{
    public function __construct(
        HttpClientInterface $wiseClient,
        CacheInterface $cache,
        array $allowedCurrencies,
        string $baseCurrency
    ) {
        parent::__construct($wiseClient, $cache, $allowedCurrencies, $baseCurrency);

        $this->client = $wiseClient;
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

        return $this->cache->get("wise.latest.$dateString", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_EXPIRY_SECONDS);

            return $this->fetchRates(['source' => $this->baseCurrency]);
        });
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

        return $this->cache->get("wise.historical.$dateString", function (ItemInterface $item) use ($date) {
            $item->expiresAfter(self::CACHE_EXPIRY_SECONDS);

            return $this->fetchRates([
                'source' => $this->baseCurrency,
                'time' => $date->toIso8601String(),
            ]);
        });
    }

    /**
     * Get exchange rates, either the latest or historical based on the given date
     *
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
     * @param array $queryParams
     * @return float[]
     * @throws JsonException
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function fetchRates(array $queryParams): array
    {
        try {
            $response = $this->client->request('GET', '/v1/rates', [
                'query' => $queryParams,
            ])->getContent();

            $rates = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            // Initialize the formatted rates with the base currency
            $formattedRates = [
                $this->baseCurrency => 1.0,
            ];
            foreach ($rates as $rate) {
                if (in_array($rate['target'], $this->allowedCurrencies, true)) {
                    $formattedRates[$rate['target']] = $rate['rate'];
                }
            }

            return $formattedRates;
        } catch (HttpExceptionInterface $e) {
            // Extract error details from the Wise API response
            $responseContent = $e->getResponse()->getContent(false);
            $errorDetails = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

            // Create a meaningful error message
            $errorMessage = sprintf(
                "Wise API error: %s. Message: %s. Status: %d.",
                $errorDetails['error'] ?? 'Unknown error',
                $errorDetails['message'] ?? 'No additional message provided',
                $errorDetails['status'] ?? $e->getCode()
            );

            // Rethrow as a custom exception with the detailed message
            throw new RuntimeException($errorMessage, $e->getCode(), $e);
        } catch (Exception $e) {
            // Handle any other type of exception
            throw new RuntimeException("An unexpected error occurred: ".$e->getMessage(), $e->getCode(), $e);
        }
    }
}
