<?php
namespace App\DataFixtures\Dev;

use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\InternetAccount;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;
use Random\RandomException;

/**
 * Generates ~18 months of realistic expense transactions across all accounts.
 *
 * Recurring items (rent, utilities, internet) are added every month.
 * Variable categories (food, transport, shopping…) are added with weighted randomness.
 * ~5 % of transactions remain as drafts to simulate pending bank imports.
 */
class ExpenseFixtures extends BaseTransactionFixtures
{
    /**
     * Approximate EUR amounts per category leaf/group.
     * These are scaled by a per-currency multiplier inside createExpense().
     */
    private const CATEGORY_BUDGETS_EUR = [
        // Food & Drinks
        'Groceries'        => [30,  100],
        'Delivery'         => [12,  40],
        'Restaurant'       => [20,  70],
        'Fast Food'        => [8,   25],
        'Coffee'           => [4,   12],
        'Bar, cafe'        => [10,  35],
        'Pizza'            => [12,  30],
        'Sushi'            => [18,  55],
        'Asian'            => [15,  45],
        'Alcohol'          => [8,   30],
        'Pastry'           => [5,   15],
        'Water'            => [2,   8],
        'Tea'              => [3,   10],
        'Goodies'          => [4,   15],
        'Canteen'          => [6,   20],
        'Eating Out'       => [15,  60],
        // Housing
        'Rent'             => [500, 700],
        'Utilities'        => [40,  80],
        'Gas'              => [15,  30],
        'Electricity'      => [20,  50],
        'Water utilities costs' => [10, 25],
        'Internet'         => [8,   15],
        'Bath'             => [5,   20],
        'Laundry'          => [4,   12],
        'Pet'              => [20,  60],
        'Furniture'        => [80,  400],
        'Tools'            => [15,  80],
        'Construction'     => [100, 600],
        'Kids'             => [40,  150],
        // Transportation
        'Public Transport' => [10,  40],
        'Taxi'             => [5,   20],
        'Car'              => [30,  200],
        // Shopping
        'Clothes'          => [40,  250],
        'Electronics'      => [80,  800],
        'Shopping'         => [20,  100],
        // Health
        'Medicine'         => [10,  60],
        'Doctor'           => [30,  120],
        // Other
        'Other'            => [5,   80],
    ];

    /** Multipliers relative to EUR (approximate, good enough for dev data) */
    private const CURRENCY_RATE = [
        'EUR' => 1.0,
        'USD' => 1.08,
        'UAH' => 41.5,
        'HUF' => 400.0,
        'BTC' => 0.000016,
    ];

    /**
     * @throws RandomException
     */
    public function load(ObjectManager $manager): void
    {
        $user             = $this->getReference('dev_user', User::class);
        $allowedCurrencies = $this->params->get('allowed_currencies');
        $now              = CarbonImmutable::now();

        $this->disableListeners();

        /** @var ExpenseCategory[] $leafCategories */
        $allCategories = $manager->getRepository(ExpenseCategory::class)->findAll();

        // Index by name for fast look-up
        $catMap = [];
        foreach ($allCategories as $c) {
            $catMap[$c->getName()] = $c;
        }

        // Accounts to generate expenses for (by reference key → typical transaction count/month)
        $accountProfiles = [
            // [ref_key, class, transactions_per_month_min, transactions_per_month_max]
            ['account_monobank_uah',   BankCardAccount::class,  18, 28],
            ['account_privatbank_uah', BankCardAccount::class,  6,  12],
            ['account_monobank_eur',   BankCardAccount::class,  4,  8],
            ['account_wise_eur',       InternetAccount::class,  3,  7],
            ['account_paypal_usd',     InternetAccount::class,  2,  5],
            ['account_cash_uah',       CashAccount::class,      4,  8],
            ['account_cash_eur',       CashAccount::class,      2,  5],
            ['account_revolut_eur',    BankCardAccount::class,  3,  6],
            ['account_otp_huf',        BankCardAccount::class,  4,  9],
        ];

        // Recurring monthly expenses: always created once per month for the right account
        // Key format matches $accountProfiles ref_key above
        $recurringByAccount = [
            'account_monobank_uah' => [
                ['Rent',                18000.0, 20000.0],
                ['Electricity',         700.0,  1200.0],
                ['Water utilities costs', 350.0, 700.0],
                ['Internet',            350.0,  500.0],
            ],
            'account_paypal_usd' => [
                ['Electronics',         9.99,   19.99],  // subscriptions
            ],
        ];

        // Category weights for random selection (higher = more likely)
        $weightedCategories = [
            'Groceries'        => 20,
            'Coffee'           => 12,
            'Delivery'         => 10,
            'Restaurant'       => 8,
            'Fast Food'        => 7,
            'Taxi'             => 7,
            'Public Transport' => 6,
            'Bar, cafe'        => 5,
            'Pastry'           => 5,
            'Alcohol'          => 4,
            'Pizza'            => 4,
            'Clothes'          => 4,
            'Medicine'         => 3,
            'Other'            => 5,
            'Sushi'            => 3,
            'Asian'            => 3,
            'Car'              => 2,
            'Doctor'           => 2,
            'Electronics'      => 2,
            'Laundry'          => 2,
            'Pet'              => 2,
            'Goodies'          => 3,
            'Tea'              => 3,
            'Canteen'          => 4,
        ];

        $weightPool = [];
        foreach ($weightedCategories as $name => $weight) {
            if (isset($catMap[$name])) {
                for ($i = 0; $i < $weight; $i++) {
                    $weightPool[] = $catMap[$name];
                }
            }
        }

        // Months to generate: current month plus 17 prior months
        for ($monthOffset = 0; $monthOffset < 18; $monthOffset++) {
            $monthStart = $now->subMonths($monthOffset)->startOfMonth();
            $monthEnd   = $monthOffset === 0 ? $now : $now->subMonths($monthOffset)->endOfMonth();

            foreach ($accountProfiles as [$refKey, $refClass, $minTx, $maxTx]) {
                /** @var Account $account */
                $account  = $this->getReference($refKey, $refClass);
                $currency = $account->getCurrency();
                $rate     = self::CURRENCY_RATE[$currency] ?? 1.0;

                // Recurring first
                foreach ($recurringByAccount[$refKey] ?? [] as [$catName, $amtMin, $amtMax]) {
                    if (!isset($catMap[$catName])) continue;
                    // Pick a random day in the month for this recurring expense
                    $daysInMonth = $monthStart->daysInMonth;
                    $day = random_int(1, min($daysInMonth, $monthOffset === 0 ? $now->day : $daysInMonth));
                    $date = $monthStart->setDay($day);

                    $amount = round($amtMin + lcg_value() * ($amtMax - $amtMin), 2);

                    $this->createExpense($manager, $account, $catMap[$catName], $user, $amount, $currency, $allowedCurrencies, $date, false);
                }

                // Random variable expenses
                $txCount = random_int($minTx, $maxTx);
                for ($i = 0; $i < $txCount; $i++) {
                    $cat      = $weightPool[array_rand($weightPool)];
                    $catName  = $cat->getName();
                    $range    = self::CATEGORY_BUDGETS_EUR[$catName] ?? [5, 50];
                    $amtEur   = $range[0] + lcg_value() * ($range[1] - $range[0]);
                    $amount   = round($amtEur * $rate, $currency === 'BTC' ? 8 : 2);

                    $daysInMonth = $monthStart->daysInMonth;
                    $maxDay = $monthOffset === 0 ? $now->day : $daysInMonth;
                    $date   = $monthStart->setDay(random_int(1, $maxDay));

                    $isDraft = random_int(1, 100) <= 5;
                    $this->createExpense($manager, $account, $cat, $user, $amount, $currency, $allowedCurrencies, $date, $isDraft);
                }
            }
        }

        $manager->flush();
        $this->enableListeners();
    }

    private function createExpense(
        ObjectManager $manager,
        Account $account,
        ExpenseCategory $category,
        $user,
        float $amount,
        string $currency,
        array $allowedCurrencies,
        CarbonImmutable $date,
        bool $isDraft,
    ): void {
        if ($category->getName() === 'Transfer' || $category->getName() === 'Debt') {
            return;
        }

        $tx = (new Expense())
            ->setAccount($account)
            ->setCategory($category)
            ->setOwner($user)
            ->setAmount((string)$amount)
            ->setConvertedValues($this->convertAmount($amount, $currency, $allowedCurrencies))
            ->setExecutedAt($date)
            ->setCreatedAt($date)
            ->setUpdatedAt($date)
            ->setIsDraft($isDraft);

        $manager->persist($tx);
    }

    public function getDependencies(): array
    {
        return array_merge(parent::getDependencies(), [AccountFixtures::class]);
    }
}

