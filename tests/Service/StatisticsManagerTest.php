<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\IncomeCategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class StatisticsManagerTest extends TestCase
{
    public function testCalculateTransactionsValueByPeriod(): void
    {
        $expenseCategory = $this->createCategory(1, 'Food');
        $incomeCategory = $this->createIncomeCategory(2, 'Salary');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $expense = $this->createTransactionMock(50.0, Transaction::EXPENSE, '2024-01-01', $expenseCategory, $account);
        $income = $this->createTransactionMock(120.0, Transaction::INCOME, '2024-01-02', $incomeCategory, $account);

        $transactionRepo = $this->createMock(TransactionRepository::class);
        $transactionRepo->expects(self::once())->method('getList')->willReturn([$expense, $income]);

        $assetsManager = $this->createAssetsManagerMock();

        $statisticsManager = $this->createStatisticsManager($assetsManager, $transactionRepo);

        $result = $statisticsManager->calculateTransactionsValueByPeriod(
            CarbonPeriod::create('2024-01-01', CarbonInterval::day(), '2024-01-03')->excludeEndDate(),
            null,
            [],
            [],
        );

        self::assertCount(2, $result);
        self::assertSame(50.0, $result[0]['expense']);
        self::assertSame(0.0, $result[0]['income']);
        self::assertSame(0.0, $result[1]['expense']);
        self::assertSame(120.0, $result[1]['income']);
    }

    public function testGenerateCategoryTreeWithValuesHydratesValueAndTotal(): void
    {
        $food = $this->createCategory(1, 'Food');
        $salary = $this->createIncomeCategory(2, 'Salary');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $transactions = [
            $this->createTransactionMock(20.0, Transaction::EXPENSE, '2024-01-01', $food, $account),
            $this->createTransactionMock(30.0, Transaction::EXPENSE, '2024-01-02', $food, $account),
            $this->createTransactionMock(100.0, Transaction::INCOME, '2024-01-03', $salary, $account),
        ];

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1],
            2 => [2],
        ]);

        $assetsManager = $this->createAssetsManagerMock();

        $statisticsManager = $this->createStatisticsManager($assetsManager, $this->createMock(TransactionRepository::class), $categoryRepo);

        $result = $statisticsManager->generateCategoryTreeWithValues($transactions, categories: [$food, $salary]);

        self::assertCount(2, $result);
        self::assertSame(50.0, $food->getValue());
        self::assertSame(50.0, $food->getTotal());
        self::assertSame(100.0, $salary->getValue());
        self::assertSame(100.0, $salary->getTotal());
    }

    public function testGenerateCategoriesOnTimelineStatistics(): void
    {
        $food = $this->createCategory(1, 'Food');
        $groceries = $this->createCategory(2, 'Groceries');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $transactions = [
            $this->createTransactionMock(10.0, Transaction::EXPENSE, '2024-01-01', $groceries, $account),
            $this->createTransactionMock(30.0, Transaction::EXPENSE, '2024-01-02', $groceries, $account),
        ];

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1, 2],
            2 => [2],
        ]);
        $categoryRepo->method('findBy')->willReturn([$food]);

        $assetsManager = $this->createAssetsManagerMock();

        $statisticsManager = $this->createStatisticsManager($assetsManager, $this->createMock(TransactionRepository::class), $categoryRepo);

        $result = $statisticsManager->generateCategoriesOnTimelineStatistics(
            CarbonPeriod::create('2024-01-01', CarbonInterval::day(), '2024-01-03'),
            [1],
            $transactions,
        );

        self::assertIsArray($result);
        self::assertArrayHasKey('Food', $result);
        self::assertCount(2, $result['Food']);
        self::assertSame(10.0, $result['Food'][0]['value']);
        self::assertSame(30.0, $result['Food'][1]['value']);
    }

    public function testGenerateAccountDistributionStatisticsSkipsZeroValues(): void
    {
        $category = $this->createCategory(1, 'Food');
        $eurAccount = $this->createAccount(1, 'Cash EUR', 'EUR');
        $uahAccount = $this->createAccount(2, 'Cash UAH', 'UAH');

        $transactions = [
            $this->createTransactionMock(10.0, Transaction::EXPENSE, '2024-01-01', $category, $eurAccount),
            $this->createTransactionMock(20.0, Transaction::EXPENSE, '2024-01-02', $category, $eurAccount),
            $this->createTransactionMock(0.0, Transaction::EXPENSE, '2024-01-02', $category, $uahAccount),
        ];

        $assetsManager = $this->createAssetsManagerMock();
        $statisticsManager = $this->createStatisticsManager($assetsManager, $this->createMock(TransactionRepository::class));

        $result = $statisticsManager->generateAccountDistributionStatistics($transactions);

        self::assertCount(1, $result);
        self::assertSame($eurAccount, $result[0]['account']);
        self::assertSame(30.0, $result[0]['amount']);
        self::assertSame(30.0, $result[0]['value']);
    }

    public function testGenerateTopValueCategoryStatisticsReturnsLargestRootCategory(): void
    {
        $food = $this->createCategory(1, 'Food');
        $salary = $this->createIncomeCategory(2, 'Salary');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $transactions = [
            $this->createTransactionMock(30.0, Transaction::EXPENSE, '2024-01-01', $food, $account),
            $this->createTransactionMock(80.0, Transaction::INCOME, '2024-01-02', $salary, $account),
        ];

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1],
            2 => [2],
        ]);
        $categoryRepo->method('findBy')->willReturn([$food, $salary]);

        $assetsManager = $this->createAssetsManagerMock();

        $statisticsManager = $this->createStatisticsManager($assetsManager, $this->createMock(TransactionRepository::class), $categoryRepo);

        $result = $statisticsManager->generateTopValueCategoryStatistics($transactions);

        self::assertNotNull($result);
        self::assertSame('Salary', $result['name']);
        self::assertSame(80.0, $result['value']);
    }

    public function testGenerateTransactionsValueByCategoriesByWeekdaysReturnsSevenDays(): void
    {
        $food = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $transactions = [
            // ordered DESC as in repository default
            $this->createTransactionMock(14.0, Transaction::EXPENSE, '2024-01-14', $food, $account),
            $this->createTransactionMock(7.0, Transaction::EXPENSE, '2024-01-07', $food, $account),
        ];

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1],
        ]);

        $expenseCategoryRepo = $this->createMock(ExpenseCategoryRepository::class);
        $expenseCategoryRepo->method('findRootCategories')->willReturn([$food]);

        $assetsManager = $this->createAssetsManagerMock();
        $statisticsManager = $this->createStatisticsManager(
            $assetsManager,
            $this->createMock(TransactionRepository::class),
            $categoryRepo,
            $expenseCategoryRepo,
        );

        $result = $statisticsManager->generateTransactionsValueByCategoriesByWeekdays($transactions);

        self::assertCount(7, $result);
        self::assertSame('Monday', $result[0]['name']);

        $nonEmptyDays = array_filter($result, static fn (array $day) => ($day['values']['Food'] ?? 0) > 0);
        self::assertNotEmpty($nonEmptyDays);
    }

    public function testGenerateTransactionsValueByCategoriesByWeekdaysEmptyInputReturnsSevenEmptyDays(): void
    {
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $expenseCategoryRepo = $this->createMock(ExpenseCategoryRepository::class);
        $expenseCategoryRepo->method('findRootCategories')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $this->createMock(TransactionRepository::class),
            $categoryRepo,
            $expenseCategoryRepo,
        );

        $result = $statisticsManager->generateTransactionsValueByCategoriesByWeekdays([]);

        self::assertCount(7, $result);
        foreach ($result as $day) {
            self::assertSame([], $day['values'], "Day {$day['name']} should have no values for empty input.");
        }
    }

    /**
     * Regression: old code used Carbon::dayOfWeek (0=Sun) as the divisor index, while buckets
     * used dayOfWeekIso-1 (0=Mon). Monday transactions were divided by Sunday counts, etc.
     *
     * 2024-01-01 is a Monday. Two Monday transactions over exactly one week → average = (10+20)/1 = 30.
     */
    public function testGenerateTransactionsValueByCategoriesByWeekdaysMondayAverageDividedByMondayCount(): void
    {
        $food = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        // Both on Mondays (2024-01-01 and 2024-01-08), ordered DESC
        $transactions = [
            $this->createTransactionMock(20.0, Transaction::EXPENSE, '2024-01-08', $food, $account),
            $this->createTransactionMock(10.0, Transaction::EXPENSE, '2024-01-01', $food, $account),
        ];

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([1 => [1]]);

        $expenseCategoryRepo = $this->createMock(ExpenseCategoryRepository::class);
        $expenseCategoryRepo->method('findRootCategories')->willReturn([$food]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $this->createMock(TransactionRepository::class),
            $categoryRepo,
            $expenseCategoryRepo,
        );

        $result = $statisticsManager->generateTransactionsValueByCategoriesByWeekdays($transactions);

        // result[0] = Monday
        self::assertSame('Monday', $result[0]['name']);
        // Range Jan 1–8 contains exactly 2 Mondays → average = 30 / 2 = 15
        self::assertEqualsWithDelta(15.0, $result[0]['values']['Food'], 0.001, 'Monday average must be divided by Monday count.');

        // Sunday (index 6) has no Food values
        self::assertSame('Sunday', $result[6]['name']);
        self::assertArrayNotHasKey('Food', $result[6]['values']);
    }

    /**
     * A single Sunday transaction must land in result[6] (Sunday), not result[0] (Monday).
     * 2024-01-07 is a Sunday (dayOfWeekIso=7, index=6).
     */
    public function testGenerateTransactionsValueByCategoriesByWeekdaysSundayLandsInCorrectSlot(): void
    {
        $food = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $transactions = [
            $this->createTransactionMock(50.0, Transaction::EXPENSE, '2024-01-07', $food, $account), // Sunday
        ];

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([1 => [1]]);

        $expenseCategoryRepo = $this->createMock(ExpenseCategoryRepository::class);
        $expenseCategoryRepo->method('findRootCategories')->willReturn([$food]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $this->createMock(TransactionRepository::class),
            $categoryRepo,
            $expenseCategoryRepo,
        );

        $result = $statisticsManager->generateTransactionsValueByCategoriesByWeekdays($transactions);

        self::assertSame('Sunday', $result[6]['name']);
        self::assertArrayHasKey('Food', $result[6]['values']);

        // Monday through Saturday must have no Food value
        foreach (\array_slice($result, 0, 6) as $day) {
            self::assertArrayNotHasKey('Food', $day['values'], "{$day['name']} must have no Food value.");
        }
    }

    public function testAverageByPeriod(): void
    {
        $category = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');

        $transactions = [
            $this->createTransactionMock(10.0, Transaction::EXPENSE, '2024-01-01', $category, $account),
            $this->createTransactionMock(30.0, Transaction::EXPENSE, '2024-01-01', $category, $account),
            $this->createTransactionMock(8.0, Transaction::EXPENSE, '2024-01-02', $category, $account),
        ];

        $assetsManager = $this->createAssetsManagerMock();
        $statisticsManager = $this->createStatisticsManager($assetsManager, $this->createMock(TransactionRepository::class));

        $result = $statisticsManager->averageByPeriod(
            $transactions,
            CarbonPeriod::create('2024-01-01', CarbonInterval::day(), '2024-01-03'),
        );

        self::assertCount(2, $result);
        self::assertSame(20.0, $result[0]['value']);
        self::assertSame(8.0, $result[1]['value']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Insights: Outlier Detection
    // ──────────────────────────────────────────────────────────────────────────

    public function testComputeBudgetInsightsReturnsCorrectShape(): void
    {
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');
        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertArrayHasKey('outliers', $result);
        self::assertArrayHasKey('trends', $result);
        self::assertArrayHasKey('seasonal', $result);
        self::assertIsArray($result['outliers']);
        self::assertIsArray($result['trends']);
        self::assertIsArray($result['seasonal']);
    }

    public function testComputeOutliersDetectsAnomalousTransaction(): void
    {
        $category = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');

        // 4 normal transactions around 30 EUR + 1 extreme outlier at 500 EUR
        $transactions = [
            $this->createOutlierTransactionMock(1, 28.0, '2024-01-05', $category, $account, 'Lunch'),
            $this->createOutlierTransactionMock(2, 32.0, '2024-01-10', $category, $account, 'Dinner'),
            $this->createOutlierTransactionMock(3, 30.0, '2024-01-15', $category, $account, 'Groceries'),
            $this->createOutlierTransactionMock(4, 29.0, '2024-01-20', $category, $account, 'Snack'),
            $this->createOutlierTransactionMock(5, 500.0, '2024-01-25', $category, $account, 'Big party'),
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($transactions);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['outliers']);
        $outlier = $result['outliers'][0];
        self::assertSame(5, $outlier['transactionId']);
        self::assertSame('Big party', $outlier['note']);
        self::assertEqualsWithDelta(500.0, $outlier['convertedAmount'], 0.01);
        self::assertEqualsWithDelta(30.0, $outlier['median'], 0.01);
        self::assertGreaterThan(5.0, $outlier['deviation']);
    }

    public function testComputeOutliersSkipsGroupsWithFewerThanThreeTransactions(): void
    {
        $category = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');

        // Only 2 transactions — group should be skipped entirely
        $transactions = [
            $this->createOutlierTransactionMock(1, 10.0, '2024-01-05', $category, $account),
            $this->createOutlierTransactionMock(2, 1000.0, '2024-01-10', $category, $account),
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($transactions);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['outliers']);
    }

    public function testComputeOutliersSkipsGroupWithIdenticalAmounts(): void
    {
        $category = $this->createCategory(1, 'Subscription');
        $account = $this->createAccount(1, 'Cash', 'EUR');
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');

        // All same amount → MAD = 0 → no outliers possible
        $transactions = [
            $this->createOutlierTransactionMock(1, 9.99, '2024-01-05', $category, $account),
            $this->createOutlierTransactionMock(2, 9.99, '2024-01-10', $category, $account),
            $this->createOutlierTransactionMock(3, 9.99, '2024-01-15', $category, $account),
            $this->createOutlierTransactionMock(4, 9.99, '2024-01-20', $category, $account),
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($transactions);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['outliers']);
    }

    public function testComputeOutliersDoesNotFlagModerateVariation(): void
    {
        $category = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');

        // Moderate variation — no value exceeds MAD threshold
        $transactions = [
            $this->createOutlierTransactionMock(1, 25.0, '2024-01-05', $category, $account),
            $this->createOutlierTransactionMock(2, 30.0, '2024-01-10', $category, $account),
            $this->createOutlierTransactionMock(3, 35.0, '2024-01-15', $category, $account),
            $this->createOutlierTransactionMock(4, 28.0, '2024-01-20', $category, $account),
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($transactions);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['outliers']);
    }

    public function testComputeOutliersSortsByDeviationDescending(): void
    {
        $category = $this->createCategory(1, 'Food');
        $account = $this->createAccount(1, 'Cash', 'EUR');
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');

        // Spread values so MAD > 0: median ~50, MAD ~5
        $transactions = [
            $this->createOutlierTransactionMock(1, 45.0, '2024-01-01', $category, $account),
            $this->createOutlierTransactionMock(2, 50.0, '2024-01-05', $category, $account),
            $this->createOutlierTransactionMock(3, 55.0, '2024-01-10', $category, $account),
            $this->createOutlierTransactionMock(4, 48.0, '2024-01-12', $category, $account),
            $this->createOutlierTransactionMock(5, 200.0, '2024-01-15', $category, $account, 'Medium outlier'),
            $this->createOutlierTransactionMock(6, 800.0, '2024-01-20', $category, $account, 'Biggest outlier'),
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($transactions);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertGreaterThanOrEqual(2, \count($result['outliers']));
        // First outlier should have the highest deviation
        self::assertSame('Biggest outlier', $result['outliers'][0]['note']);
    }

    public function testComputeOutliersHandlesMultipleCategories(): void
    {
        $food = $this->createCategory(1, 'Food');
        $transport = $this->createCategory(2, 'Transport');
        $account = $this->createAccount(1, 'Cash', 'EUR');
        $budget = $this->createBudgetMock('2024-01-01', '2024-01-31');

        $transactions = [
            // Food group: 3 normal + 1 outlier
            $this->createOutlierTransactionMock(1, 20.0, '2024-01-01', $food, $account),
            $this->createOutlierTransactionMock(2, 22.0, '2024-01-05', $food, $account),
            $this->createOutlierTransactionMock(3, 21.0, '2024-01-10', $food, $account),
            $this->createOutlierTransactionMock(4, 400.0, '2024-01-15', $food, $account, 'Food outlier'),
            // Transport group: only 2 transactions — should be skipped
            $this->createOutlierTransactionMock(5, 5.0, '2024-01-01', $transport, $account),
            $this->createOutlierTransactionMock(6, 500.0, '2024-01-10', $transport, $account),
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($transactions);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        // Only Food outlier should appear (Transport group has < 3 transactions)
        self::assertCount(1, $result['outliers']);
        self::assertSame(1, $result['outliers'][0]['categoryId']);
    }

    public function testComputeOutliersEmptyBudgetPeriodReturnsEmpty(): void
    {
        $budget = $this->createBudgetMock('2024-06-01', '2024-06-30');
        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn([]);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['outliers']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Insights: Trend Detection
    // ──────────────────────────────────────────────────────────────────────────

    public function testComputeTrendsDetectsUpwardTrend(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        // 6 months before July: Jan-Jun 2024
        // Cat 1: older months (Jan-Mar) avg ~100, recent months (Apr-Jun) avg ~200 → +100% up
        $byMonth = [
            1 => [
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 90.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 110.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 190.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 210.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($this->createBudgetPeriodActivityStubs([1]));
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['trends']);
        self::assertSame('up', $result['trends'][0]['direction']);
        self::assertSame(1, $result['trends'][0]['categoryId']);
        self::assertGreaterThan(90.0, $result['trends'][0]['changePercent']);
    }

    public function testComputeTrendsDetectsDownwardTrend(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        $byMonth = [
            1 => [
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 80.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 80.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 80.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($this->createBudgetPeriodActivityStubs([1]));
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['trends']);
        self::assertSame('down', $result['trends'][0]['direction']);
        self::assertLessThan(-50.0, $result['trends'][0]['changePercent']);
    }

    public function testComputeTrendsIgnoresStableCategories(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        // All months roughly the same → change < 10% → stable → excluded
        $byMonth = [
            1 => [
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 102.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 98.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 101.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 99.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['trends']);
    }

    public function testComputeTrendsSkipsCategoriesWithFewerThanThreeMonths(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        // Only 2 active months → should be skipped
        $byMonth = [
            1 => [
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 500.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['trends']);
    }

    public function testComputeTrendsSortsByAbsoluteChangeDescending(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        $byMonth = [
            // Category 1: +50% change
            1 => [
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 150.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 150.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 150.0]],
            ],
            // Category 2: -70% change (bigger absolute change)
            2 => [
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 300.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 300.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 300.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 90.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 90.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 90.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($this->createBudgetPeriodActivityStubs([1, 2]));
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(2, $result['trends']);
        // Category 2 (-70%) has bigger absolute change than category 1 (+50%)
        self::assertSame(2, $result['trends'][0]['categoryId']);
        self::assertSame(1, $result['trends'][1]['categoryId']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Insights: Seasonal Patterns
    // ──────────────────────────────────────────────────────────────────────────

    public function testComputeSeasonalPatternsDetectsHighSeason(): void
    {
        // Budget is for December → should detect December as high-spending month
        $budget = $this->createBudgetMock('2024-12-01', '2024-12-31');

        // 24 months of data: Dec has 2x the average
        $byMonth = [
            1 => [
                '2023-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2023-12' => ['EUR' => ['income' => 0.0, 'expense' => 300.0]],
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['seasonal']);
        self::assertSame(1, $result['seasonal'][0]['categoryId']);
        self::assertGreaterThan(1.3, $result['seasonal'][0]['seasonalFactor']);
        self::assertSame(1, $result['seasonal'][0]['sampleYears']);
    }

    public function testComputeSeasonalPatternsDetectsLowSeason(): void
    {
        // Budget for February → February historically has lower spending
        $budget = $this->createBudgetMock('2024-02-01', '2024-02-29');

        $byMonth = [
            1 => [
                '2022-02' => ['EUR' => ['income' => 0.0, 'expense' => 30.0]],
                '2022-06' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2022-10' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2023-02' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2023-10' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['seasonal']);
        self::assertLessThan(0.7, $result['seasonal'][0]['seasonalFactor']);
    }

    public function testComputeSeasonalPatternsIgnoresNormalMonths(): void
    {
        // Budget for March → March spending is close to average → no flag
        $budget = $this->createBudgetMock('2024-03-01', '2024-03-31');

        $byMonth = [
            1 => [
                '2022-03' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2022-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2023-03' => ['EUR' => ['income' => 0.0, 'expense' => 110.0]],
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 90.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['seasonal']);
    }

    public function testComputeSeasonalPatternsSkipsCategoryWithTooFewMonths(): void
    {
        $budget = $this->createBudgetMock('2024-12-01', '2024-12-31');

        // Only 1 month of data → not enough for seasonal analysis
        $byMonth = [
            1 => [
                '2023-12' => ['EUR' => ['income' => 0.0, 'expense' => 500.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['seasonal']);
    }

    public function testComputeSeasonalPatternsNoMatchingMonthReturnsEmpty(): void
    {
        // Budget for July, but historical data has no July entries
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        $byMonth = [
            1 => [
                '2023-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');
        self::assertEmpty($result['seasonal']);
    }

    public function testComputeSeasonalPatternsMultipleSampleYears(): void
    {
        $budget = $this->createBudgetMock('2024-12-01', '2024-12-31');

        // Two December data points available
        $byMonth = [
            1 => [
                '2022-12' => ['EUR' => ['income' => 0.0, 'expense' => 400.0]],
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2023-12' => ['EUR' => ['income' => 0.0, 'expense' => 500.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['seasonal']);
        self::assertSame(2, $result['seasonal'][0]['sampleYears']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Insights: Tree Rollup
    // ──────────────────────────────────────────────────────────────────────────

    public function testComputeTrendsRollsUpChildrenIntoRootCategory(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        // Category 1 = root "Food", category 2 = child "Groceries", category 3 = child "Eating Out"
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1, 2, 3],
            2 => [2],
            3 => [3],
        ]);
        $categoryRepo->method('buildParentMap')->willReturn([
            1 => null,
            2 => 1,
            3 => 1,
        ]);

        // Leaf-level monthly data (no data on root category 1 directly)
        $byMonth = [
            2 => [ // Groceries — trending up
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 80.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 85.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 90.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 120.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 130.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 140.0]],
            ],
            3 => [ // Eating Out — stable
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
            ],
        ];

        // Budget-period activity stubs — children assigned under root 1 via parentMap
        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($this->createBudgetPeriodActivityStubs([2]));
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
            $categoryRepo,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        // Root category "Food" (id=1) should appear with rolled-up trend
        self::assertCount(1, $result['trends']);
        self::assertSame(1, $result['trends'][0]['categoryId']);
        self::assertSame('up', $result['trends'][0]['direction']);

        // Children array should include Groceries (trending up) but not Eating Out (stable)
        self::assertArrayHasKey('children', $result['trends'][0]);
        /** @var list<array{categoryId: int}> $children */
        $children = $result['trends'][0]['children'];
        $childIds = array_column($children, 'categoryId');
        self::assertContains(2, $childIds, 'Groceries child trend should be included');
    }

    public function testComputeSeasonalRollsUpChildrenIntoRootCategory(): void
    {
        $budget = $this->createBudgetMock('2024-12-01', '2024-12-31');

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1, 2, 3],
            2 => [2],
            3 => [3],
        ]);
        $categoryRepo->method('buildParentMap')->willReturn([
            1 => null,
            2 => 1,
            3 => 1,
        ]);

        // Child categories: December spending is higher than average
        $byMonth = [
            2 => [
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2023-12' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
            ],
            3 => [
                '2023-06' => ['EUR' => ['income' => 0.0, 'expense' => 30.0]],
                '2023-12' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 30.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn([]);
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
            $categoryRepo,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        // Root category 1 should appear with seasonal pattern (Dec is high)
        self::assertCount(1, $result['seasonal']);
        self::assertSame(1, $result['seasonal'][0]['categoryId']);
        self::assertGreaterThan(1.3, $result['seasonal'][0]['seasonalFactor']);

        // Children should be present
        self::assertArrayHasKey('children', $result['seasonal'][0]);
        self::assertNotEmpty($result['seasonal'][0]['children']);
    }

    public function testComputeTrendsChildrenArrayEmptyWhenNoChildTrendsSignificant(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        // Single root with no children in descendant map
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1],
        ]);
        $categoryRepo->method('buildParentMap')->willReturn([
            1 => null,
        ]);

        $byMonth = [
            1 => [
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 100.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 200.0]],
            ],
        ];

        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($this->createBudgetPeriodActivityStubs([1]));
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
            $categoryRepo,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        self::assertCount(1, $result['trends']);
        self::assertSame(1, $result['trends'][0]['categoryId']);
        self::assertSame([], $result['trends'][0]['children']);
    }

    public function testComputeTrendsAggregatesGrandchildrenIntoIntermediateCategory(): void
    {
        $budget = $this->createBudgetMock('2024-07-01', '2024-07-31');

        // 3-level tree: Food(1) → Groceries(2) → Organic(4) + Regular(5), plus EatingOut(3)
        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('buildDescendantMap')->willReturn([
            1 => [1, 2, 3, 4, 5],
            2 => [2, 4, 5],
            3 => [3],
            4 => [4],
            5 => [5],
        ]);
        $categoryRepo->method('buildParentMap')->willReturn([
            1 => null,
            2 => 1,
            3 => 1,
            4 => 2,
            5 => 2,
        ]);

        // Only leaf categories have direct transactions
        $byMonth = [
            4 => [ // Organic — trending up
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 20.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 25.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 30.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 60.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 70.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 80.0]],
            ],
            5 => [ // Regular — stable
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 50.0]],
            ],
            3 => [ // Eating Out — stable
                '2024-01' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
                '2024-02' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
                '2024-03' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
                '2024-04' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
                '2024-05' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
                '2024-06' => ['EUR' => ['income' => 0.0, 'expense' => 40.0]],
            ],
        ];

        // Budget-period activity stubs — leaf categories grouped to root 1 via parentMap
        $transactionRepository = $this->createMock(TransactionRepository::class);
        $transactionRepository->method('getList')->willReturn($this->createBudgetPeriodActivityStubs([4]));
        $transactionRepository->method('getConvertedMonthlyTotalsByCategory')->willReturn($byMonth);

        $statisticsManager = $this->createStatisticsManager(
            $this->createAssetsManagerMock(),
            $transactionRepository,
            $categoryRepo,
        );

        $result = $statisticsManager->computeBudgetInsights($budget, 'EUR');

        // Root "Food" (id=1) should appear with trend based on all descendants
        self::assertCount(1, $result['trends']);
        self::assertSame(1, $result['trends'][0]['categoryId']);

        // Direct children of root should be Groceries(2) and EatingOut(3), not leaf categories
        /** @var list<array{categoryId: int, olderAverage: float, recentAverage: float}> $children */
        $children = $result['trends'][0]['children'];
        $childIds = array_column($children, 'categoryId');
        self::assertContains(2, $childIds, 'Groceries (intermediate) should be a direct child');
        self::assertNotContains(4, $childIds, 'Organic (grandchild) should not be a direct child of root');
        self::assertNotContains(5, $childIds, 'Regular (grandchild) should not be a direct child of root');

        // Groceries child trend should include aggregated data from Organic + Regular
        $groceriesTrend = null;
        foreach ($children as $child) {
            if (2 === $child['categoryId']) {
                $groceriesTrend = $child;
                break;
            }
        }
        self::assertNotNull($groceriesTrend);

        // Groceries aggregated: Organic + Regular per month
        // Older avg: (70 + 75 + 80) / 3 = 75
        // Recent avg: (110 + 120 + 130) / 3 = 120
        self::assertEqualsWithDelta(75.0, $groceriesTrend['olderAverage'], 0.01);
        self::assertEqualsWithDelta(120.0, $groceriesTrend['recentAverage'], 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function createBudgetMock(string $startDate, string $endDate): Budget
    {
        $budget = $this->createMock(Budget::class);
        $budget->method('getStartDate')->willReturn(CarbonImmutable::parse($startDate));
        $budget->method('getEndDate')->willReturn(CarbonImmutable::parse($endDate));

        return $budget;
    }

    /**
     * Creates minimal Transaction stubs for budget-period activity detection.
     * Each stub only needs getCategory() to resolve the root category via parentMap.
     *
     * @param int[] $categoryIds
     *
     * @return Transaction[]
     */
    private function createBudgetPeriodActivityStubs(array $categoryIds): array
    {
        $stubs = [];
        foreach ($categoryIds as $categoryId) {
            $category = $this->createCategory($categoryId, "Cat{$categoryId}");
            $stub = $this->createMock(Transaction::class);
            $stub->method('getCategory')->willReturn($category);
            $stubs[] = $stub;
        }

        return $stubs;
    }

    /**
     * Creates a transaction mock suitable for outlier detection.
     * Unlike createTransactionMock(), this returns getConvertedValue() and getId().
     */
    private function createOutlierTransactionMock(
        int $identifier,
        float $convertedAmount,
        string $executedAt,
        Category $category,
        Account $account,
        ?string $note = null,
    ): Transaction {
        /** @var MockObject&Transaction $transaction */
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getId')->willReturn($identifier);
        $transaction->method('getType')->willReturn(Transaction::EXPENSE);
        $transaction->method('getExecutedAt')->willReturn(CarbonImmutable::parse($executedAt));
        $transaction->method('getCategory')->willReturn($category);
        $transaction->method('getAccount')->willReturn($account);
        $transaction->method('getValue')->willReturn($convertedAmount);
        $transaction->method('getConvertedValue')->willReturn($convertedAmount);
        $transaction->method('getAmount')->willReturn($convertedAmount);
        $transaction->method('getNote')->willReturn($note);

        return $transaction;
    }

    private function createStatisticsManager(
        AssetsManager $assetsManager,
        TransactionRepository $transactionRepository,
        ?CategoryRepository $categoryRepository = null,
        ?ExpenseCategoryRepository $expenseCategoryRepository = null,
        ?IncomeCategoryRepository $incomeCategoryRepository = null,
    ): StatisticsManager {
        $categoryRepository ??= $this->createMock(CategoryRepository::class);
        $expenseCategoryRepository ??= $this->createMock(ExpenseCategoryRepository::class);
        $incomeCategoryRepository ??= $this->createMock(IncomeCategoryRepository::class);

        return new StatisticsManager(
            $assetsManager,
            $transactionRepository,
            $categoryRepository,
            $expenseCategoryRepository,
            $incomeCategoryRepository,
        );
    }

    private function createAssetsManagerMock(): AssetsManager
    {
        /** @var MockObject&AssetsManager $assetsManager */
        $assetsManager = $this->createMock(AssetsManager::class);
        $assetsManager
            ->method('sumTransactions')
            ->willReturnCallback(static function (array $transactions): float {
                return array_reduce(
                    $transactions,
                    static fn (float $sum, Transaction $transaction): float => $sum + $transaction->getValue(),
                    0.0,
                );
            });

        return $assetsManager;
    }

    private function createCategory(int $id, string $name): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setName($name)
            ->setCreatedAt(CarbonImmutable::now())
            ->setUpdatedAt(CarbonImmutable::now());

        $this->setEntityId($category, $id);

        return $category;
    }

    private function createIncomeCategory(int $id, string $name): IncomeCategory
    {
        $category = (new IncomeCategory())
            ->setName($name)
            ->setCreatedAt(CarbonImmutable::now())
            ->setUpdatedAt(CarbonImmutable::now());

        $this->setEntityId($category, $id);

        return $category;
    }

    private function createAccount(int $id, string $name, string $currency): Account
    {
        $account = (new Account())
            ->setName($name)
            ->setCurrency($currency);

        $this->setEntityId($account, $id);

        return $account;
    }

    private function createTransactionMock(
        float $value,
        string $type,
        string $executedAt,
        Category $category,
        Account $account,
    ): Transaction {
        /** @var MockObject&Transaction $transaction */
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('getType')->willReturn($type);
        $transaction->method('getExecutedAt')->willReturn(CarbonImmutable::parse($executedAt));
        $transaction->method('getCategory')->willReturn($category);
        $transaction->method('getAccount')->willReturn($account);
        $transaction->method('getValue')->willReturn($value);

        return $transaction;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $class = new ReflectionClass($entity);

        while (!$class->hasProperty('id') && false !== $class->getParentClass()) {
            $class = $class->getParentClass();
        }

        $property = $class->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
