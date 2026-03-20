<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\BankCardAccount;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\CashAccount;
use App\Entity\Category;
use App\Entity\Debt;
use App\Entity\ExchangeRateSnapshot;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\InternetAccount;
use App\Entity\Transfer;
use App\Entity\User;
use App\EventListener\DebtConvertedValueListener;
use App\EventListener\TransactionListener;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DemoSeedService
{
    private const FLUSH_BATCH_SIZE = 200;
    private const SEED_MONTHS = 24;
    private const BUDGET_HISTORY_MONTHS = 12;

    /** Base exchange rates (approximate, early 2026) */
    private const BASE_RATE_USD_PER_EUR = 1.08;
    private const BASE_RATE_HUF_PER_EUR = 390.0;
    private const BASE_RATE_UAH_PER_EUR = 44.0;
    private const BASE_RATE_EUR_PER_BTC = 82000.0;

    /** Allowed currencies for convertedValues computation */
    private const ALLOWED_CURRENCIES = ['EUR', 'USD', 'HUF', 'UAH', 'BTC'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionListener $transactionListener,
        private readonly DebtConvertedValueListener $debtConvertedValueListener,
        private readonly DemoSeedTransactionFactory $transactionFactory,
    ) {
    }

    public function seedForUser(User $user, SymfonyStyle $io): void
    {
        $this->disableListeners();
        $this->disableOwnableFilter();

        $io->text('Generating exchange rate snapshots…');
        $ratesByDate = $this->seedExchangeRateSnapshots();

        $io->text('Creating categories…');
        $categories = $this->seedCategories($user);

        $io->text('Creating accounts…');
        $accounts = $this->seedAccounts($user);

        $io->text('Creating transactions…');
        $this->transactionFactory->seedTransactions($user, $accounts, $categories, $ratesByDate);

        $io->text('Creating budget…');
        $latestRate = end($ratesByDate);
        assert($latestRate instanceof ExchangeRateSnapshot);
        $this->seedBudget($user, $accounts, $categories, $latestRate);

        $io->text('Creating debt…');
        $this->seedDebt($user, $accounts, $ratesByDate);

        $this->entityManager->flush();
        $this->enableListeners();
    }

    public function removeDataForUser(User $user): void
    {
        $this->disableOwnableFilter();

        // Order matters: entities with FK dependencies must be removed first.
        $this->removeTransfers($user);
        $this->entityManager->flush();

        // Budgets must go before Categories: BudgetLine has a non-cascading FK on category_id.
        // Removing Budget cascades → BudgetLine, clearing the FK before categories are deleted.
        $this->removeBudgets($user);
        $this->entityManager->flush();

        $this->removeCategories($user);  // cascades → Transactions
        $this->entityManager->flush();

        $this->removeAccounts($user);
        $this->entityManager->flush();

        $this->removeDebts($user);
        $this->entityManager->flush();
    }

    // ─── Removal helpers ──────────────────────────────────────────────────────

    private function removeTransfers(User $user): void
    {
        $transfers = $this->entityManager->getRepository(Transfer::class)->findBy(['owner' => $user]);

        foreach ($transfers as $transfer) {
            $this->entityManager->remove($transfer);
        }
    }

    private function removeCategories(User $user): void
    {
        // Only remove root categories — cascade removes children and their transactions.
        $roots = $this->entityManager
            ->getRepository(ExpenseCategory::class)
            ->createQueryBuilder('category')
            ->where('category.owner = :user')
            ->andWhere('category.parent IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $incomeRoots = $this->entityManager
            ->getRepository(IncomeCategory::class)
            ->createQueryBuilder('category')
            ->where('category.owner = :user')
            ->andWhere('category.parent IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        foreach (array_merge($roots, $incomeRoots) as $root) {
            $this->entityManager->remove($root);
        }
    }

    private function removeAccounts(User $user): void
    {
        $accounts = $this->entityManager->getRepository(Account::class)->findBy(['owner' => $user]);

        foreach ($accounts as $account) {
            $this->entityManager->remove($account);
        }
    }

    private function removeDebts(User $user): void
    {
        $debts = $this->entityManager->getRepository(Debt::class)->findBy(['owner' => $user]);

        foreach ($debts as $debt) {
            $this->entityManager->remove($debt);
        }
    }

    private function removeBudgets(User $user): void
    {
        $budgets = $this->entityManager->getRepository(Budget::class)->findBy(['owner' => $user]);

        foreach ($budgets as $budget) {
            $this->entityManager->remove($budget);
        }
    }

    // ─── Seeding ──────────────────────────────────────────────────────────────

    /**
     * Generates daily exchange rate snapshots for SEED_MONTHS months back.
     * Uses a sine wave + trend + noise pattern for each pair so charts look realistic.
     *
     * @return ExchangeRateSnapshot[] keyed by 'Y-m-d'
     */
    private function seedExchangeRateSnapshots(): array
    {
        $ratesByDate = [];
        $totalDays = self::SEED_MONTHS * 31;
        $persistedCount = 0;

        for ($dayOffset = $totalDays; $dayOffset >= 0; --$dayOffset) {
            $date = new DateTimeImmutable(sprintf('-%d days', $dayOffset));
            $key = $date->format('Y-m-d');

            // Skip if a snapshot for this date already exists
            $existing = $this->entityManager
                ->getRepository(ExchangeRateSnapshot::class)
                ->findOneBy(['effectiveAt' => $date]);

            if (null !== $existing) {
                $ratesByDate[$key] = $existing;
                continue;
            }

            $progress = $dayOffset / $totalDays;
            $seasonalWave = sin(2 * M_PI * ($totalDays - $dayOffset) / 365);

            $usdPerEur = round(
                self::BASE_RATE_USD_PER_EUR
                + 0.03 * $seasonalWave
                + (1.0 - $progress) * 0.04
                + (lcg_value() * 0.012 - 0.006),
                6,
            );
            $hufPerEur = round(
                self::BASE_RATE_HUF_PER_EUR
                + 15.0 * $seasonalWave
                + (1.0 - $progress) * 10.0
                + (lcg_value() * 6.0 - 3.0),
                2,
            );
            $uahPerEur = round(
                self::BASE_RATE_UAH_PER_EUR
                + 1.5 * $seasonalWave
                + (1.0 - $progress) * 2.0
                + (lcg_value() * 0.8 - 0.4),
                4,
            );
            // BTC is more volatile: larger amplitude and noise
            $eurPerBtc = round(
                self::BASE_RATE_EUR_PER_BTC
                + 12000.0 * $seasonalWave
                + (1.0 - $progress) * 8000.0
                + (lcg_value() * 5000.0 - 2500.0),
                2,
            );

            $snapshot = (new ExchangeRateSnapshot())
                ->setEffectiveAt($date)
                ->setUsdPerEur((string) max(0.9, $usdPerEur))
                ->setHufPerEur((string) max(300.0, $hufPerEur))
                ->setUahPerEur((string) max(35.0, $uahPerEur))
                ->setEurPerBtc((string) max(20000.0, $eurPerBtc));

            $this->entityManager->persist($snapshot);
            $ratesByDate[$key] = $snapshot;

            ++$persistedCount;
            if (0 === $persistedCount % self::FLUSH_BATCH_SIZE) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        return $ratesByDate;
    }

    /**
     * Creates the full expense + income category tree owned by the user.
     *
     * @return array{expense: array<string, ExpenseCategory>, income: array<string, IncomeCategory>}
     */
    private function seedCategories(User $user): array
    {
        $expenseTree = $this->buildExpenseCategoryTree();
        $incomeCategoryTree = $this->buildIncomeCategoryTree();

        $expenseMap = $this->persistExpenseCategoryTree($expenseTree, $user);
        $incomeMap = $this->persistIncomeCategoryTree($incomeCategoryTree, $user);

        $this->entityManager->flush();

        return ['expense' => $expenseMap, 'income' => $incomeMap];
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, ExpenseCategory>
     */
    private function persistExpenseCategoryTree(array $tree, User $user, ?ExpenseCategory $parent = null): array
    {
        $map = [];

        foreach ($tree as $name => $children) {
            $category = new ExpenseCategory($name);
            $category->setOwner($user);

            if (is_array($children) && isset($children['_profit']) && false === $children['_profit']) {
                $category->setIsAffectingProfit(false);
            }

            if (null !== $parent) {
                $category->setParent($parent);
            }

            $this->entityManager->persist($category);
            $map[$name] = $category;

            /** @var array<string, mixed> $childTree */
            $childTree = is_array($children) ? array_filter($children, static fn($key) => '_profit' !== $key, ARRAY_FILTER_USE_KEY) : [];

            if ([] !== $childTree) {
                foreach ($this->persistExpenseCategoryTree($childTree, $user, $category) as $childName => $child) {
                    $map[$childName] = $child;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, IncomeCategory>
     */
    private function persistIncomeCategoryTree(array $tree, User $user, ?IncomeCategory $parent = null): array
    {
        $map = [];

        foreach ($tree as $name => $children) {
            $category = new IncomeCategory($name);
            $category->setOwner($user);

            if (is_array($children) && isset($children['_profit']) && false === $children['_profit']) {
                $category->setIsAffectingProfit(false);
            }

            if (null !== $parent) {
                $category->setParent($parent);
            }

            $this->entityManager->persist($category);
            $map[$name] = $category;

            /** @var array<string, mixed> $childTree */
            $childTree = is_array($children) ? array_filter($children, static fn($key) => '_profit' !== $key, ARRAY_FILTER_USE_KEY) : [];

            if ([] !== $childTree) {
                foreach ($this->persistIncomeCategoryTree($childTree, $user, $category) as $childName => $child) {
                    $map[$childName] = $child;
                }
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExpenseCategoryTree(): array
    {
        return [
            'Food & Drinks' => [
                'Groceries' => [],
                'Supermarket' => [],
                'Delivery' => [],
                'Restaurant' => [],
                'Fast Food' => [],
                'Coffee & Tea' => [],
                'Bar & Nightlife' => [],
                'Alcohol' => [],
                'Street Food' => [],
                'Canteen' => [],
                'Bakery' => [],
            ],
            'Housing' => [
                'Rent' => [],
                'Utilities' => [
                    'Gas' => [],
                    'Electricity' => [],
                    'Water' => [],
                    'Internet' => [],
                    'Phone' => [],
                ],
                'Furniture & Decor' => [],
                'Household Items' => [],
                'Repairs' => [],
                'Cleaning' => [],
                'Laundry' => [],
            ],
            'Transportation' => [
                'Public Transport' => [],
                'Taxi & Rideshare' => [],
                'Fuel' => [],
                'Parking' => [],
                'Car Maintenance' => [],
                'Flight' => [],
            ],
            'Shopping' => [
                'Clothes & Accessories' => [],
                'Electronics' => [],
                'Books & Media' => [],
                'Gifts' => [],
                'Household Shopping' => [],
            ],
            'Health & Fitness' => [
                'Doctor' => [],
                'Dentist' => [],
                'Pharmacy' => [],
                'Gym' => [],
                'Sports & Activities' => [],
            ],
            'Entertainment' => [
                'Cinema & Theatre' => [],
                'Subscriptions' => [],
                'Games & Hobbies' => [],
                'Concerts & Events' => [],
                'Travel & Vacation' => [],
            ],
            'Personal Care' => [
                'Haircut & Beauty' => [],
                'Cosmetics' => [],
            ],
            'Pets' => [
                'Pet Food' => [],
                'Vet & Medicine' => [],
            ],
            'Education' => [
                'Courses & Training' => [],
                'Books & Learning' => [],
            ],
            'Other' => [],
            'Transfer' => ['_profit' => false],
            'Debt' => ['_profit' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIncomeCategoryTree(): array
    {
        return [
            'Salary' => [
                'Main Salary' => [],
                'Advance' => [],
            ],
            'Freelance' => [
                'Project Payment' => [],
                'Consulting' => [],
            ],
            'Investments' => [
                'Dividends' => [],
                'Trading Profit' => [],
                'Interest' => [],
            ],
            'Return' => [
                'Cashback' => [],
                'Tax Refund' => [],
                'Refund' => [],
            ],
            'Bonus' => [
                'Annual Bonus' => [],
                'Performance Bonus' => [],
                'Referral' => [],
            ],
            'Rental Income' => [],
            'Gifts & Other' => [],
            'Transfer' => ['_profit' => false],
            'Debt' => ['_profit' => false],
        ];
    }

    /**
     * @return array<string, Account>
     */
    private function seedAccounts(User $user): array
    {
        $accounts = [
            'main_eur' => (new BankCardAccount())
                ->setName('Main Account')
                ->setCurrency('EUR')
                ->setBalance('4200.00')
                ->setIsDisplayedOnSidebar(true)
                ->setOwner($user),

            'wise_usd' => (new InternetAccount())
                ->setName('Wise')
                ->setCurrency('USD')
                ->setBalance('6800.00')
                ->setIsDisplayedOnSidebar(true)
                ->setOwner($user),

            'cash_eur' => (new CashAccount())
                ->setName('Cash EUR')
                ->setCurrency('EUR')
                ->setBalance('450.00')
                ->setIsDisplayedOnSidebar(false)
                ->setOwner($user),

            'cash_usd' => (new CashAccount())
                ->setName('Cash USD')
                ->setCurrency('USD')
                ->setBalance('320.00')
                ->setIsDisplayedOnSidebar(false)
                ->setOwner($user),

            'mono_uah' => (new BankCardAccount())
                ->setName('Monobank')
                ->setCurrency('UAH')
                ->setBalance('28000.00')
                ->setIsDisplayedOnSidebar(true)
                ->setOwner($user),

            'btc_wallet' => (new Account())
                ->setName('Bitcoin Wallet')
                ->setCurrency('BTC')
                ->setBalance('0.03120000')
                ->setIsDisplayedOnSidebar(false)
                ->setOwner($user),

            'revolut_eur' => (new InternetAccount())
                ->setName('Revolut')
                ->setCurrency('EUR')
                ->setBalance('1850.00')
                ->setIsDisplayedOnSidebar(true)
                ->setOwner($user),

            'savings_eur' => (new BankCardAccount())
                ->setName('Savings')
                ->setCurrency('EUR')
                ->setBalance('12400.00')
                ->setIsDisplayedOnSidebar(false)
                ->setOwner($user),
        ];

        foreach ($accounts as $account) {
            $this->entityManager->persist($account);
        }

        $this->entityManager->flush();

        return $accounts;
    }

    /**
     * Base planned amounts (EUR) for budget lines. Historical budgets apply a small
     * monthly drift so older budgets show slightly different allocations.
     *
     * @return array<string, float>
     */
    private function buildBaseBudgetLines(): array
    {
        return [
            'Groceries'              => 250.00,
            'Restaurant'             => 150.00,
            'Delivery'               => 80.00,
            'Coffee & Tea'           => 60.00,
            'Bar & Nightlife'        => 80.00,
            'Rent'                   => 900.00,
            'Internet'               => 25.00,
            'Electricity'            => 50.00,
            'Public Transport'       => 50.00,
            'Taxi & Rideshare'       => 40.00,
            'Subscriptions'          => 35.00,
            'Pharmacy'               => 30.00,
            'Gym'                    => 40.00,
            'Clothes & Accessories'  => 100.00,
            'Personal Care'          => 40.00,
            'Entertainment'          => 80.00,
            'Other'                  => 50.00,
        ];
    }

    /**
     * Creates the current month budget plus BUDGET_HISTORY_MONTHS past monthly budgets.
     *
     * @param array<string, Account> $accounts
     * @param array{expense: array<string, ExpenseCategory>, income: array<string, IncomeCategory>} $categories
     */
    private function seedBudget(User $user, array $accounts, array $categories, ExchangeRateSnapshot $latestRate): void
    {
        $baseLines = $this->buildBaseBudgetLines();

        // Current month first (monthOffset = 0), then 12 historical months
        for ($monthOffset = 0; $monthOffset <= self::BUDGET_HISTORY_MONTHS; ++$monthOffset) {
            $monthDate = CarbonImmutable::now()->subMonths($monthOffset);
            $name = $monthOffset === 0
                ? 'Monthly Budget'
                : $monthDate->format('F Y');

            $budget = new Budget();
            $budget->setName($name);
            $budget->setOwner($user);
            $budget->setPeriodType('monthly');
            $budget->setStartDate($monthDate->startOfMonth());
            $budget->setEndDate($monthDate->endOfMonth());
            $this->entityManager->persist($budget);

            // Older budgets have slightly lower planned amounts (simulates gradual lifestyle inflation)
            $ageFactor = 1.0 - ($monthOffset * 0.008);

            foreach ($baseLines as $categoryName => $plannedAmount) {
                $category = $categories['expense'][$categoryName] ?? null;

                if (null === $category) {
                    continue;
                }

                // Add small noise so budgets don't look identical
                $noise = 1.0 + (lcg_value() * 0.06 - 0.03);
                $adjustedAmount = round($plannedAmount * $ageFactor * $noise, 2);

                $line = new BudgetLine();
                $line->setBudget($budget);
                $line->setCategory($category);
                $line->setPlannedAmount((string) $adjustedAmount);
                $line->setPlannedCurrency('EUR');
                $this->entityManager->persist($line);
            }
        }
    }

    /**
     * @param array<string, Account> $accounts
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    private function seedDebt(User $user, array $accounts, array $ratesByDate): void
    {
        $date = CarbonImmutable::now()->subMonths(2)->setDay(15);
        $rate = $this->findRateForDate($ratesByDate, $date->format('Y-m-d'));

        $debt = new Debt();
        $debt->setOwner($user);
        $debt->setDebtor('Alex (borrowed for laptop)');
        $debt->setCurrency('EUR');
        $debt->setBalance('280.00');
        $debt->setConvertedValues($this->computeConvertedValues(280.0, 'EUR', $rate));
        $debt->setNote('Borrowed €280 for MacBook accessories');
        $debt->setCreatedAt($date);
        $this->entityManager->persist($debt);
    }

    // ─── Utilities ────────────────────────────────────────────────────────────

    /**
     * @param ExchangeRateSnapshot[] $ratesByDate
     */
    public function findRateForDate(array $ratesByDate, string $dateKey): ExchangeRateSnapshot
    {
        if (isset($ratesByDate[$dateKey])) {
            return $ratesByDate[$dateKey];
        }

        // Fall back to nearest available snapshot
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
    public function computeConvertedValues(float $amount, string $currency, ExchangeRateSnapshot $rate): array
    {
        $result = [];

        foreach (self::ALLOWED_CURRENCIES as $targetCurrency) {
            $converted = $rate->convert($amount, $currency, $targetCurrency);
            $precision = 'BTC' === $targetCurrency ? 8 : 2;
            $result[$targetCurrency] = round($converted ?? 0.0, $precision);
        }

        return $result;
    }

    private function disableListeners(): void
    {
        $this->transactionListener->setEnabled(false);
        $this->debtConvertedValueListener->setEnabled(false);
    }

    private function enableListeners(): void
    {
        $this->transactionListener->setEnabled(true);
        $this->debtConvertedValueListener->setEnabled(true);
    }

    private function disableOwnableFilter(): void
    {
        $filters = $this->entityManager->getFilters();

        if ($filters->isEnabled('ownable')) {
            $filters->disable('ownable');
        }
    }
}
