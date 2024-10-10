<?php

namespace App\Service;

use App\Entity\BankCardAccount;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MonobankService extends BaseExchangeRatesProvider
{
    private EntityManagerInterface $em;

    private IncomeCategory $unknownIncomeCategory;

    private ExpenseCategory $unknownExpenseCategory;

    public function __construct(
        EntityManagerInterface $em,
        CacheInterface $cache,
        HttpClientInterface $monobankClient,
        string $baseCurrency,
        array $allowedCurrencies
    ) {
        parent::__construct($monobankClient, $cache, $allowedCurrencies, $baseCurrency);
        $this->em = $em;

        $this->unknownIncomeCategory = $this->em->getRepository(IncomeCategory::class)->find(
            Category::INCOME_CATEGORY_ID_UNKNOWN
        );
        $this->unknownExpenseCategory = $this->em->getRepository(ExpenseCategory::class)->find(
            Category::EXPENSE_CATEGORY_ID_UNKNOWN
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function convertStatementItemToDraftTransaction(string $accountId, array $statementItem): ?Transaction
    {
        if (!$account = $this->em->getRepository(BankCardAccount::class)->findOneByMonobankId($accountId)) {
            throw new InvalidArgumentException('Account ID is not registered in system.');
        }

        $isIncome = $statementItem['amount'] > 0;
        $user = $account->getOwner();
        $amount = abs($statementItem['amount'] / 100);
        $note = $statementItem['description'].' '.(array_key_exists(
                'comment',
                $statementItem
            ) ? $statementItem['comment'] : '');

        $draftTransaction = $isIncome ? new Income(true) : new Expense(true);
        $draftTransaction
            ->setCategory($isIncome ? $this->unknownIncomeCategory : $this->unknownExpenseCategory)
            ->setAmount($amount)
            ->setAccount($account)
            ->setNote($note)
            ->setExecutedAt(Carbon::createFromTimestamp($statementItem['time']))
            ->setOwner($user);

        return $draftTransaction;
    }

    /**
     * Get Monobank rates with caching
     *
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getLatest(): array
    {
        $now = CarbonImmutable::now();
        $dateString = $now->toDateString();

        return $this->cache->get("monobank_rates.$dateString", function (ItemInterface $item) {
            $item->expiresAfter(self::CACHE_EXPIRY_SECONDS);

            return $this->fetchRates();
        });
    }

    /**
     * Fetch rates from Monobank API
     *
     * @return array
     * @throws ClientExceptionInterface
     * @throws JsonException
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function fetchRates(): array
    {
        // Map ISO 4217 numeric codes to currency codes
        $currencyCodeMap = [
            348 => 'HUF',
            840 => 'USD',
            978 => 'EUR',
            980 => 'UAH',
        ];

        try {
            $response = $this->client->request('GET', '/bank/currency')->getContent();
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            $currencyToUahRates = [];

            foreach ($data as $rateInfo) {
                $currencyCodeA = $rateInfo['currencyCodeA'];
                $currencyCodeB = $rateInfo['currencyCodeB'];
                $currencyA = $currencyCodeMap[$currencyCodeA] ?? null;
                $currencyB = $currencyCodeMap[$currencyCodeB] ?? null;

                if (!$currencyA || !$currencyB) {
                    continue;
                }

                // If currency B is UAH, get the rate for currency A to UAH
                if ($currencyCodeB === 980 && in_array($currencyA, $this->allowedCurrencies, true)) {
                    $rate = $rateInfo['rateCross'] ?? (($rateInfo['rateBuy'] + $rateInfo['rateSell']) / 2);
                    $currencyToUahRates[$currencyA] = $rate;
                }
            }

            // Add base currency (UAH) rate as 1
            $currencyToUahRates['UAH'] = 1.0;

            // Check if base currency rate is available
            if (!isset($currencyToUahRates[$this->baseCurrency])) {
                throw new RuntimeException("Base currency rate to UAH not available for {$this->baseCurrency}");
            }

            $baseCurrencyToUahRate = $currencyToUahRates[$this->baseCurrency];

            // Convert rates to be relative to the base currency
            $rates = [];
            foreach ($currencyToUahRates as $currency => $rateToUah) {
                $rates[$currency] = $baseCurrencyToUahRate / $rateToUah;
            }

            // Set base currency rate as 1.0
            $rates[$this->baseCurrency] = 1.0;

            // Filter out only allowed currencies
            return array_filter($rates, function ($currency) {
                return in_array($currency, $this->allowedCurrencies, true);
            }, ARRAY_FILTER_USE_KEY);
        } catch (HttpExceptionInterface $e) {
            // Extract error details from the response
            $responseContent = $e->getResponse()->getContent(false);
            $errorDetails = json_decode($responseContent, true, 512, JSON_THROW_ON_ERROR);

            $errorMessage = sprintf(
                "Monobank API error: %s. Message: %s.",
                $errorDetails['error'] ?? 'Unknown error',
                $errorDetails['message'] ?? 'No additional message provided'
            );

            throw new RuntimeException($errorMessage, $e->getCode(), $e);
        } catch (Exception $e) {
            throw new RuntimeException("An unexpected error occurred: ".$e->getMessage(), $e->getCode(), $e);
        }
    }
}
