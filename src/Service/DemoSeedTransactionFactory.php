<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\ExchangeRateSnapshot;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates expense and income transactions for demo seeding.
 * Targets 55–90 expenses per month and €3,000–€6,000 equivalent income per month.
 */
class DemoSeedTransactionFactory
{
    private const FLUSH_BATCH_SIZE = 300;

    private const SEED_MONTHS = 24;

    /** EUR amount ranges [min, max] per expense category leaf */
    private const CATEGORY_BUDGETS_EUR = [
        'Groceries'           => [25, 95],
        'Supermarket'         => [30, 110],
        'Delivery'            => [10, 38],
        'Restaurant'          => [18, 65],
        'Fast Food'           => [6, 20],
        'Coffee & Tea'        => [3, 10],
        'Bar & Nightlife'     => [15, 55],
        'Alcohol'             => [8, 28],
        'Street Food'         => [4, 14],
        'Canteen'             => [5, 18],
        'Bakery'              => [3, 12],
        'Rent'                => [750, 1050],
        'Gas'                 => [18, 35],
        'Electricity'         => [22, 55],
        'Water'               => [8, 20],
        'Internet'            => [10, 28],
        'Phone'               => [8, 20],
        'Furniture & Decor'   => [50, 350],
        'Household Items'     => [12, 80],
        'Repairs'             => [40, 300],
        'Cleaning'            => [8, 25],
        'Laundry'             => [4, 12],
        'Public Transport'    => [8, 38],
        'Taxi & Rideshare'    => [5, 22],
        'Fuel'                => [30, 80],
        'Parking'             => [3, 12],
        'Car Maintenance'     => [40, 250],
        'Flight'              => [80, 450],
        'Clothes & Accessories' => [35, 220],
        'Electronics'         => [60, 700],
        'Books & Media'       => [8, 35],
        'Gifts'               => [20, 120],
        'Household Shopping'  => [15, 70],
        'Doctor'              => [30, 130],
        'Dentist'             => [40, 180],
        'Pharmacy'            => [8, 55],
        'Gym'                 => [30, 65],
        'Sports & Activities' => [20, 90],
        'Cinema & Theatre'    => [10, 30],
        'Subscriptions'       => [5, 20],
        'Games & Hobbies'     => [10, 60],
        'Concerts & Events'   => [15, 80],
        'Travel & Vacation'   => [60, 600],
        'Haircut & Beauty'    => [15, 55],
        'Cosmetics'           => [12, 60],
        'Pet Food'            => [20, 55],
        'Vet & Medicine'      => [30, 180],
        'Courses & Training'  => [30, 200],
        'Books & Learning'    => [10, 50],
        'Other'               => [5, 70],
    ];

    /** Category probability weights for random variable expenses */
    private const CATEGORY_WEIGHTS = [
        'Groceries'           => 22,
        'Supermarket'         => 14,
        'Coffee & Tea'        => 16,
        'Delivery'            => 12,
        'Restaurant'          => 10,
        'Fast Food'           => 9,
        'Bar & Nightlife'     => 7,
        'Public Transport'    => 8,
        'Taxi & Rideshare'    => 7,
        'Canteen'             => 6,
        'Bakery'              => 5,
        'Street Food'         => 4,
        'Pharmacy'            => 5,
        'Subscriptions'       => 6,
        'Laundry'             => 4,
        'Clothes & Accessories' => 4,
        'Haircut & Beauty'    => 3,
        'Cosmetics'           => 3,
        'Alcohol'             => 3,
        'Cinema & Theatre'    => 3,
        'Games & Hobbies'     => 2,
        'Books & Media'       => 2,
        'Household Items'     => 2,
        'Pet Food'            => 2,
        'Other'               => 4,
    ];

    /** Currency multipliers relative to EUR for accounts not using EUR */
    private const CURRENCY_MULTIPLIER = [
        'EUR' => 1.0,
        'USD' => 1.08,
        'UAH' => 44.0,
        'HUF' => 390.0,
        'BTC' => 0.000012,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, Account> $accounts
     * @param array{expense: array<string, ExpenseCategory>, income: array<string, IncomeCategory>} $categories
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    public function seedTransactions(User $user, array $accounts, array $categories, array $ratesByDate): void
    {
        $weightPool = $this->buildWeightPool($categories['expense']);
        $now = CarbonImmutable::now();
        $persistedCount = 0;

        for ($monthOffset = 0; $monthOffset < self::SEED_MONTHS; ++$monthOffset) {
            $monthStart = $now->subMonths($monthOffset)->startOfMonth();
            $monthEnd = 0 === $monthOffset ? $now : $now->subMonths($monthOffset)->endOfMonth();

            $persistedCount += $this->seedMonthlyExpenses(
                $user, $accounts, $categories['expense'], $weightPool, $ratesByDate, $monthStart, $monthEnd, $monthOffset,
            );
            $persistedCount += $this->seedMonthlyIncome(
                $user, $accounts, $categories['income'], $ratesByDate, $monthStart, $monthOffset, $now,
            );

            if ($persistedCount >= self::FLUSH_BATCH_SIZE) {
                $this->entityManager->flush();
                $persistedCount = 0;
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @param array<string, Account> $accounts
     * @param array<string, ExpenseCategory> $expenseCategories
     * @param ExpenseCategory[] $weightPool
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    private function seedMonthlyExpenses(
        User $user,
        array $accounts,
        array $expenseCategories,
        array $weightPool,
        array $ratesByDate,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        int $monthOffset,
    ): int {
        $count = 0;

        // Recurring fixed expenses (always once per month)
        $count += $this->seedRecurringExpenses($user, $accounts, $expenseCategories, $ratesByDate, $monthStart, $monthOffset);

        // Variable expenses per account
        $accountProfiles = [
            //  [key,            minTx, maxTx]
            ['main_eur',      20, 30],
            ['cash_eur',      10, 16],
            ['cash_usd',       5, 10],
            ['wise_usd',       6, 12],
            ['mono_uah',       8, 14],
            ['revolut_eur',    4,  9],
            ['savings_eur',    0,  2],
        ];

        foreach ($accountProfiles as [$accountKey, $minTransactions, $maxTransactions]) {
            $account = $accounts[$accountKey] ?? null;

            if (null === $account) {
                continue;
            }

            $currency = $account->getCurrency();
            $multiplier = self::CURRENCY_MULTIPLIER[$currency] ?? 1.0;
            $txCount = random_int($minTransactions, $maxTransactions);

            for ($index = 0; $index < $txCount; ++$index) {
                $category = $weightPool[array_rand($weightPool)];
                $categoryName = $category->getName();
                [$minEur, $maxEur] = self::CATEGORY_BUDGETS_EUR[$categoryName] ?? [5, 50];
                $amountEur = $minEur + lcg_value() * ($maxEur - $minEur);
                $amount = round($amountEur * $multiplier, 'BTC' === $currency ? 8 : 2);

                $maxDay = 0 === $monthOffset ? $monthStart->diffInDays($monthEnd) + 1 : $monthStart->daysInMonth;
                $date = $monthStart->addDays(random_int(0, max(0, $maxDay - 1)));
                $rate = $this->findRateForDate($ratesByDate, $date->format('Y-m-d'));

                $expense = $this->buildExpense($user, $account, $category, $amount, $currency, $rate, $date, false);
                $this->entityManager->persist($expense);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, Account> $accounts
     * @param array<string, ExpenseCategory> $expenseCategories
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    private function seedRecurringExpenses(
        User $user,
        array $accounts,
        array $expenseCategories,
        array $ratesByDate,
        CarbonImmutable $monthStart,
        int $monthOffset,
    ): int {
        $count = 0;
        $mainEur = $accounts['main_eur'] ?? null;
        $wiseUsd = $accounts['wise_usd'] ?? null;

        $recurringExpenses = [
            // [account_key, category_name, min_eur, max_eur, day_of_month]
            ['main_eur', 'Rent',        800.0, 950.0,  1],
            ['main_eur', 'Electricity',  28.0,  55.0,  5],
            ['main_eur', 'Water',         9.0,  18.0,  5],
            ['main_eur', 'Internet',     12.0,  25.0,  7],
            ['main_eur', 'Phone',         8.0,  18.0, 10],
            ['main_eur', 'Gym',          35.0,  55.0,  2],
            ['wise_usd', 'Subscriptions', 4.0,  18.0, 14],
            ['wise_usd', 'Subscriptions', 9.0,  15.0, 22],
        ];

        foreach ($recurringExpenses as [$accountKey, $categoryName, $minEur, $maxEur, $dayOfMonth]) {
            $account = $accounts[$accountKey] ?? null;
            $category = $expenseCategories[$categoryName] ?? null;

            if (null === $account || null === $category) {
                continue;
            }

            $daysInMonth = $monthStart->daysInMonth;
            $day = min($dayOfMonth, $daysInMonth);
            $date = $monthStart->setDay($day);

            // Skip if the date is in the future (current month may be partial)
            if ($monthOffset === 0 && $date->greaterThan(CarbonImmutable::now())) {
                continue;
            }

            $currency = $account->getCurrency();
            $multiplier = self::CURRENCY_MULTIPLIER[$currency] ?? 1.0;
            $amountEur = $minEur + lcg_value() * ($maxEur - $minEur);
            $amount = round($amountEur * $multiplier, 'BTC' === $currency ? 8 : 2);
            $rate = $this->findRateForDate($ratesByDate, $date->format('Y-m-d'));

            $expense = $this->buildExpense($user, $account, $category, $amount, $currency, $rate, $date, false);
            $this->entityManager->persist($expense);
            ++$count;
        }

        return $count;
    }

    /**
     * @param array<string, Account> $accounts
     * @param array<string, IncomeCategory> $incomeCategories
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    private function seedMonthlyIncome(
        User $user,
        array $accounts,
        array $incomeCategories,
        array $ratesByDate,
        CarbonImmutable $monthStart,
        int $monthOffset,
        CarbonImmutable $now,
    ): int {
        $count = 0;
        $mainEur     = $accounts['main_eur'] ?? null;
        $wiseUsd     = $accounts['wise_usd'] ?? null;
        $revolutEur  = $accounts['revolut_eur'] ?? null;

        $catSalary    = $incomeCategories['Main Salary'] ?? $incomeCategories['Salary'] ?? null;
        $catAdvance   = $incomeCategories['Advance'] ?? null;
        $catFreelance = $incomeCategories['Project Payment'] ?? $incomeCategories['Freelance'] ?? null;
        $catBonus     = $incomeCategories['Performance Bonus'] ?? $incomeCategories['Bonus'] ?? null;
        $catCashback  = $incomeCategories['Cashback'] ?? $incomeCategories['Return'] ?? null;

        // Main salary — last day of month to main EUR account
        if (null !== $mainEur && null !== $catSalary) {
            $salaryDate = $monthStart->lastOfMonth()->setTime(17, 0);

            if ($salaryDate->lte($now)) {
                $amount = round(2500.0 + lcg_value() * 700.0, 2);
                $rate = $this->findRateForDate($ratesByDate, $salaryDate->format('Y-m-d'));
                $income = $this->buildIncome($user, $mainEur, $catSalary, $amount, 'EUR', $rate, $salaryDate, 'Monthly salary');
                $this->entityManager->persist($income);
                ++$count;
            }
        }

        // Salary advance — 15th of month
        if (null !== $mainEur && null !== $catAdvance) {
            $advanceDate = $monthStart->setDay(15)->setTime(10, 0);

            if ($advanceDate->lte($now)) {
                $amount = round(800.0 + lcg_value() * 400.0, 2);
                $rate = $this->findRateForDate($ratesByDate, $advanceDate->format('Y-m-d'));
                $income = $this->buildIncome($user, $mainEur, $catAdvance, $amount, 'EUR', $rate, $advanceDate, 'Salary advance');
                $this->entityManager->persist($income);
                ++$count;
            }
        }

        // Bi-monthly USD freelance via Wise (even months)
        if (0 === $monthOffset % 2 && null !== $wiseUsd && null !== $catFreelance) {
            $freelanceDate = $monthStart->setDay(random_int(3, 20))->setTime(9, 30);

            if ($freelanceDate->lte($now)) {
                $amount = round(1800.0 + lcg_value() * 2200.0, 2);
                $rate = $this->findRateForDate($ratesByDate, $freelanceDate->format('Y-m-d'));
                $income = $this->buildIncome($user, $wiseUsd, $catFreelance, $amount, 'USD', $rate, $freelanceDate, 'Freelance payment');
                $this->entityManager->persist($income);
                ++$count;
            }
        }

        // Quarterly bonus (every 3 months)
        if (0 === $monthOffset % 3 && null !== $mainEur && null !== $catBonus) {
            $bonusDate = $monthStart->setDay(random_int(18, 28))->setTime(12, 0);

            if ($bonusDate->lte($now)) {
                $amount = round(500.0 + lcg_value() * 1000.0, 2);
                $rate = $this->findRateForDate($ratesByDate, $bonusDate->format('Y-m-d'));
                $income = $this->buildIncome($user, $mainEur, $catBonus, $amount, 'EUR', $rate, $bonusDate, 'Quarterly bonus');
                $this->entityManager->persist($income);
                ++$count;
            }
        }

        // Monthly cashback to main account
        if (null !== $mainEur && null !== $catCashback) {
            $cashbackDate = $monthStart->setDay(1)->setTime(8, 0);

            if ($cashbackDate->lte($now)) {
                $amount = round(12.0 + lcg_value() * 35.0, 2);
                $rate = $this->findRateForDate($ratesByDate, $cashbackDate->format('Y-m-d'));
                $income = $this->buildIncome($user, $mainEur, $catCashback, $amount, 'EUR', $rate, $cashbackDate, 'Cashback');
                $this->entityManager->persist($income);
                ++$count;
            }
        }

        // Revolut cashback (every other month)
        if (0 === $monthOffset % 2 && null !== $revolutEur && null !== $catCashback) {
            $revolutCashbackDate = $monthStart->setDay(3)->setTime(9, 15);

            if ($revolutCashbackDate->lte($now)) {
                $amount = round(5.0 + lcg_value() * 20.0, 2);
                $rate = $this->findRateForDate($ratesByDate, $revolutCashbackDate->format('Y-m-d'));
                $income = $this->buildIncome($user, $revolutEur, $catCashback, $amount, 'EUR', $rate, $revolutCashbackDate, 'Revolut cashback');
                $this->entityManager->persist($income);
                ++$count;
            }
        }

        return $count;
    }

    private function buildExpense(
        User $user,
        Account $account,
        ExpenseCategory $category,
        float $amount,
        string $currency,
        ExchangeRateSnapshot $rate,
        CarbonImmutable $date,
        bool $isDraft,
    ): Expense {
        $executedAt = $date->setTime(random_int(7, 22), random_int(0, 59), random_int(0, 59));

        $expense = new Expense();
        $expense->setOwner($user);
        $expense->setAccount($account);
        $expense->setCategory($category);
        $expense->setAmount((string) $amount);
        $expense->setConvertedValues($this->computeConvertedValues($amount, $currency, $rate));
        $expense->setExecutedAt($executedAt);
        $expense->setCreatedAt($executedAt);
        $expense->setUpdatedAt($executedAt);
        $expense->setIsDraft($isDraft);

        return $expense;
    }

    private function buildIncome(
        User $user,
        Account $account,
        IncomeCategory $category,
        float $amount,
        string $currency,
        ExchangeRateSnapshot $rate,
        CarbonImmutable $date,
        string $note,
    ): Income {
        $income = new Income();
        $income->setOwner($user);
        $income->setAccount($account);
        $income->setCategory($category);
        $income->setAmount((string) $amount);
        $income->setConvertedValues($this->computeConvertedValues($amount, $currency, $rate));
        $income->setNote($note);
        $income->setExecutedAt($date);
        $income->setCreatedAt($date);
        $income->setUpdatedAt($date);
        $income->setIsDraft(false);

        return $income;
    }

    /**
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    private function findRateForDate(array $ratesByDate, string $dateKey): ExchangeRateSnapshot
    {
        if (isset($ratesByDate[$dateKey])) {
            return $ratesByDate[$dateKey];
        }

        $keys = array_keys($ratesByDate);
        $closest = $keys[0];

        $targetTimestamp = (int) strtotime($dateKey);

        foreach ($keys as $key) {
            $keyTimestamp = (int) strtotime($key);
            $closestTimestamp = (int) strtotime($closest);

            if (abs($keyTimestamp - $targetTimestamp) < abs($closestTimestamp - $targetTimestamp)) {
                $closest = $key;
            }
        }

        return $ratesByDate[$closest];
    }

    /**
     * @return array<string, float>
     */
    private function computeConvertedValues(float $amount, string $currency, ExchangeRateSnapshot $rate): array
    {
        $allowedCurrencies = ['EUR', 'USD', 'HUF', 'UAH', 'BTC'];
        $result = [];

        foreach ($allowedCurrencies as $targetCurrency) {
            $converted = $rate->convert($amount, $currency, $targetCurrency);
            $precision = 'BTC' === $targetCurrency ? 8 : 2;
            $result[$targetCurrency] = round($converted ?? 0.0, $precision);
        }

        return $result;
    }

    /**
     * Builds a weighted pool of expense categories for random selection.
     * Higher weight = more likely to be picked.
     *
     * @param array<string, ExpenseCategory> $expenseCategories
     * @return ExpenseCategory[]
     */
    private function buildWeightPool(array $expenseCategories): array
    {
        $pool = [];

        foreach (self::CATEGORY_WEIGHTS as $name => $weight) {
            $category = $expenseCategories[$name] ?? null;

            if (null === $category) {
                continue;
            }

            for ($index = 0; $index < $weight; ++$index) {
                $pool[] = $category;
            }
        }

        return $pool;
    }
}
