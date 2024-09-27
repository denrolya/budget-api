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
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MonobankService
{
    private const MONTH_IN_SECONDS = 2678400;
    private const API_URL = 'https://api.monobank.ua/bank/currency';

    private EntityManagerInterface $em;

    private Security $security;

    private IncomeCategory $unknownIncomeCategory;

    private ExpenseCategory $unknownExpenseCategory;

    private CacheInterface $cache;

    public function __construct(EntityManagerInterface $em, CacheInterface $cache, Security $security)
    {
        $this->em = $em;
        $this->cache = $cache;
        $this->security = $security;
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
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getMonobankRates(): array
    {

        $baseCurrency = $this->security->getUser()->getBaseCurrency();

        // Map ISO 4217 numeric codes to currency codes
        $currencyCodeMap = [
            840 => 'USD',
            978 => 'EUR',
            348 => 'HUF',
            980 => 'UAH',
            // Add other currency codes as needed
        ];

        // List of desired currencies
        $desiredCurrencies = ['EUR', 'USD', 'HUF', 'UAH'];

        $now = CarbonImmutable::now();
        $dateString = $now->toDateString();
        $client = HttpClient::create();

        // Cache the rates
        return $this->cache->get(
            "monobank_rates.$dateString",
            function (ItemInterface $item) use ($client, $currencyCodeMap, $desiredCurrencies, $baseCurrency) {
                $item->expiresAfter(self::MONTH_IN_SECONDS);

                // Fetch Monobank API response
                $response = $client->request('GET', 'https://api.monobank.ua/bank/currency')->getContent();

                $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

                // Build mapping from currency code to rate to UAH
                $currencyToUahRates = [];

                foreach ($data as $rateInfo) {
                    $currencyCodeA = $rateInfo['currencyCodeA'];
                    $currencyCodeB = $rateInfo['currencyCodeB'];
                    $currencyA = $currencyCodeMap[$currencyCodeA] ?? null;
                    $currencyB = $currencyCodeMap[$currencyCodeB] ?? null;

                    if (!$currencyA || !$currencyB) {
                        continue; // Skip unknown currencies
                    }

                    // We are interested in rates where currencyCodeB is UAH (980)
                    if ($currencyCodeB == 980 && in_array($currencyA, $desiredCurrencies, true)) {
                        // Use rateCross if available, otherwise average of rateBuy and rateSell
                        $rate = $rateInfo['rateCross'] ?? (($rateInfo['rateBuy'] + $rateInfo['rateSell']) / 2);

                        $currencyToUahRates[$currencyA] = $rate;
                    }
                }

                // Include UAH rate to UAH as 1
                $currencyToUahRates['UAH'] = 1.0;

                // Check if base currency rate to UAH is available
                if (!isset($currencyToUahRates[$baseCurrency])) {
                    throw new \RuntimeException("Base currency rate to UAH not available for {$baseCurrency}");
                }

                $baseCurrencyToUahRate = $currencyToUahRates[$baseCurrency];

                // Now, for each currency, calculate rate relative to base currency
                $rates = [];
                foreach ($currencyToUahRates as $currency => $rateToUah) {
                    $rates[$currency] = $baseCurrencyToUahRate / $rateToUah;
                }

                // Set base currency rate to 1
                $rates[$baseCurrency] = 1.0;

                // Filter only desired currencies
                return array_filter($rates, function ($currency) use ($desiredCurrencies) {
                    return in_array($currency, $desiredCurrencies, true);
                }, ARRAY_FILTER_USE_KEY);
            }
        );
    }
}
