<?php

namespace App\Tests\Service;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\ExpenseCategory;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

        $nonEmptyDays = array_filter($result, static fn(array $day) => ($day['values']['Food'] ?? 0) > 0);
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
        foreach (array_slice($result, 0, 6) as $day) {
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
            CarbonPeriod::create('2024-01-01', CarbonInterval::day(), '2024-01-03')
        );

        self::assertCount(2, $result);
        self::assertSame(20.0, $result[0]['value']);
        self::assertSame(8.0, $result[1]['value']);
    }

    private function createStatisticsManager(
        AssetsManager $assetsManager,
        TransactionRepository $transactionRepo,
        ?CategoryRepository $categoryRepo = null,
        ?ExpenseCategoryRepository $expenseCategoryRepo = null,
    ): StatisticsManager {
        $categoryRepo ??= $this->createMock(CategoryRepository::class);
        $expenseCategoryRepo ??= $this->createMock(ExpenseCategoryRepository::class);

        /** @var MockObject&EntityManagerInterface $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em
            ->method('getRepository')
            ->willReturnCallback(static function (string $entityClass) use ($transactionRepo, $categoryRepo, $expenseCategoryRepo) {
                return match ($entityClass) {
                    Transaction::class => $transactionRepo,
                    Category::class => $categoryRepo,
                    ExpenseCategory::class => $expenseCategoryRepo,
                    default => throw new \InvalidArgumentException('Unexpected repository request: '.$entityClass),
                };
            });

        return new StatisticsManager($assetsManager, $em);
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
                    static fn(float $sum, Transaction $tx): float => $sum + $tx->getValue(),
                    0.0
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
        /** @var MockObject&Transaction $tx */
        $tx = $this->createMock(Transaction::class);
        $tx->method('getType')->willReturn($type);
        $tx->method('getExecutedAt')->willReturn(CarbonImmutable::parse($executedAt));
        $tx->method('getCategory')->willReturn($category);
        $tx->method('getAccount')->willReturn($account);
        $tx->method('getValue')->willReturn($value);

        return $tx;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $class = new \ReflectionClass($entity);

        while (!$class->hasProperty('id') && $class->getParentClass() !== false) {
            $class = $class->getParentClass();
        }

        $property = $class->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
