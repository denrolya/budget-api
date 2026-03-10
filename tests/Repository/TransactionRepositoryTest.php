<?php

namespace App\Tests\Repository;

use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionRepositoryTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private TransactionRepository $repo;
    private Account $eurAccount;
    private Account $uahAccount;
    private ExpenseCategory $expenseCategory;
    private IncomeCategory $incomeCategory;
    private User $owner;

    /** @var int[] */
    private array $createdTransactionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = self::getContainer()->get('doctrine')->getManager();
        /** @var TransactionRepository $repo */
        $repo = $this->em->getRepository(Transaction::class);
        $this->repo = $repo;

        $eurAccount = $this->em->getRepository(Account::class)->findOneBy(['name' => 'EUR Cash']);
        $uahAccount = $this->em->getRepository(Account::class)->findOneBy(['name' => 'UAH Card']);
        $expenseCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['name' => 'Groceries']);
        $incomeCategory = $this->em->getRepository(IncomeCategory::class)->findOneBy(['name' => 'Salary']);

        assert($eurAccount instanceof Account);
        assert($uahAccount instanceof Account);
        assert($expenseCategory instanceof ExpenseCategory);
        assert($incomeCategory instanceof IncomeCategory);

        $owner = $eurAccount->getOwner();
        assert($owner instanceof User);

        $this->eurAccount = $eurAccount;
        $this->uahAccount = $uahAccount;
        $this->expenseCategory = $expenseCategory;
        $this->incomeCategory = $incomeCategory;
        $this->owner = $owner;
    }

    protected function tearDown(): void
    {
        if ($this->em !== null && $this->createdTransactionIds !== []) {
            $transactions = $this->em->getRepository(Transaction::class)->findBy(['id' => $this->createdTransactionIds]);
            foreach ($transactions as $transaction) {
                $this->em->remove($transaction);
            }
            $this->em->flush();
            $this->createdTransactionIds = [];
        }

        $this->em?->clear();
        $this->em = null;

        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testGetListFiltersByNoteAndEscapesLikeChars(): void
    {
        $prefix = 'repo-note-'.uniqid('', true);

        $match = $this->createExpense(
            amount: 10,
            note: $prefix.' literal %_ token',
            executedAt: CarbonImmutable::parse('2026-01-05 10:00:00'),
            account: $this->eurAccount,
            convertedEur: 10,
        );

        $this->createExpense(
            amount: 11,
            note: $prefix.' plain token',
            executedAt: CarbonImmutable::parse('2026-01-05 11:00:00'),
            account: $this->eurAccount,
            convertedEur: 11,
        );

        $result = $this->repo->getList(
            note: '%_ token',
            affectingProfitOnly: false,
            after: CarbonImmutable::parse('2026-01-01'),
            before: CarbonImmutable::parse('2026-01-10'),
        );

        $ids = array_map(static fn(Transaction $t) => $t->getId(), $result);
        self::assertContains($match->getId(), $ids);
        self::assertCount(1, array_values(array_filter($result, static fn(Transaction $t) => str_contains($t->getNote() ?? '', $prefix))));
    }

    public function testGetListFiltersByCurrencies(): void
    {
        $prefix = 'repo-currency-'.uniqid('', true);

        $eurTx = $this->createExpense(
            amount: 15,
            note: $prefix,
            executedAt: CarbonImmutable::parse('2026-01-06 09:00:00'),
            account: $this->eurAccount,
            convertedEur: 15,
        );

        $uahTx = $this->createExpense(
            amount: 500,
            note: $prefix,
            executedAt: CarbonImmutable::parse('2026-01-06 12:00:00'),
            account: $this->uahAccount,
            convertedEur: 15,
        );

        $result = $this->repo->getList(
            currencies: ['uah'],
            note: $prefix,
            affectingProfitOnly: false,
            after: CarbonImmutable::parse('2026-01-01'),
            before: CarbonImmutable::parse('2026-01-10'),
        );

        $ids = array_map(static fn(Transaction $t) => $t->getId(), $result);
        self::assertContains($uahTx->getId(), $ids);
        self::assertNotContains($eurTx->getId(), $ids);

        foreach ($result as $tx) {
            self::assertSame('UAH', $tx->getAccount()->getCurrency());
        }
    }

    public function testGetListRejectsInvalidCurrencyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid currency');

        $this->repo->getList(currencies: ['USDT']);
    }

    public function testSumConvertedForMixedAndTypedQueries(): void
    {
        $prefix = 'repo-sum-'.uniqid('', true);

        $this->createIncome(
            amount: 120,
            note: $prefix,
            executedAt: CarbonImmutable::parse('2026-01-07 08:00:00'),
            account: $this->eurAccount,
            convertedEur: 120,
        );

        $this->createExpense(
            amount: 30,
            note: $prefix,
            executedAt: CarbonImmutable::parse('2026-01-07 13:00:00'),
            account: $this->eurAccount,
            convertedEur: 30,
        );

        $commonArgs = [
            'baseCurrency' => 'EUR',
            'note' => $prefix,
            'affectingProfitOnly' => false,
            'after' => CarbonImmutable::parse('2026-01-01'),
            'before' => CarbonImmutable::parse('2026-01-10'),
        ];

        self::assertSame(90.0, $this->repo->sumConverted(...$commonArgs));
        self::assertSame(120.0, $this->repo->sumConverted(...$commonArgs, type: Transaction::INCOME));
        self::assertSame(-30.0, $this->repo->sumConverted(...$commonArgs, type: Transaction::EXPENSE));
    }

    public function testGetPaginatorRespectsLimitPageAndOrder(): void
    {
        $prefix = 'repo-paginator-'.uniqid('', true);

        $this->createExpense(10, $prefix, CarbonImmutable::parse('2026-01-01 10:00:00'), $this->eurAccount, 10);
        $this->createExpense(20, $prefix, CarbonImmutable::parse('2026-01-02 10:00:00'), $this->eurAccount, 20);
        $last = $this->createExpense(30, $prefix, CarbonImmutable::parse('2026-01-03 10:00:00'), $this->eurAccount, 30);

        $paginator = $this->repo->getPaginator(
            after: CarbonImmutable::parse('2026-01-01'),
            before: CarbonImmutable::parse('2026-01-04'),
            affectingProfitOnly: false,
            note: $prefix,
            limit: 2,
            page: 2,
            orderField: 'executedAt',
            order: 'ASC',
        );

        $results = iterator_to_array($paginator->getResults(), false);

        self::assertSame(3, $paginator->getNumResults());
        self::assertCount(1, $results);
        self::assertSame($last->getId(), $results[0]->getId());
    }

    public function testCountByDayForFiltersPivotsIncomeAndExpenseByCurrency(): void
    {
        $prefix = 'repo-daily-'.uniqid('', true);

        $this->createIncome(100, $prefix, CarbonImmutable::parse('2026-01-08 09:00:00'), $this->eurAccount, 100);
        $this->createExpense(40, $prefix, CarbonImmutable::parse('2026-01-08 18:00:00'), $this->eurAccount, 40);
        $this->createExpense(500, $prefix, CarbonImmutable::parse('2026-01-09 10:00:00'), $this->uahAccount, 15);

        $rows = $this->repo->countByDay(
            after: CarbonImmutable::parse('2026-01-08'),
            before: CarbonImmutable::parse('2026-01-09'),
            note: $prefix,
            affectingProfitOnly: false,
        );

        self::assertCount(2, $rows);

        self::assertSame('2026-01-08', $rows[0]['day']);
        self::assertSame(2, $rows[0]['count']);
        self::assertSame(100.0, $rows[0]['convertedValues']['EUR']['income']);
        self::assertSame(40.0, $rows[0]['convertedValues']['EUR']['expense']);

        self::assertSame('2026-01-09', $rows[1]['day']);
        self::assertSame(1, $rows[1]['count']);
        self::assertSame(0.0, $rows[1]['convertedValues']['UAH']['income']);
        self::assertSame(500.0, $rows[1]['convertedValues']['UAH']['expense']);
    }

    public function testCountByDayForFiltersWithTypeIncomeOnly(): void
    {
        $prefix = 'repo-daily-income-'.uniqid('', true);

        $this->createIncome(75, $prefix, CarbonImmutable::parse('2026-01-10 09:00:00'), $this->eurAccount, 75);
        $this->createExpense(15, $prefix, CarbonImmutable::parse('2026-01-10 11:00:00'), $this->eurAccount, 15);

        $rows = $this->repo->countByDay(
            after: CarbonImmutable::parse('2026-01-10'),
            before: CarbonImmutable::parse('2026-01-10'),
            note: $prefix,
            type: Transaction::INCOME,
            affectingProfitOnly: false,
        );

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['count']);
        self::assertSame(75.0, $rows[0]['convertedValues']['EUR']['income']);
        self::assertSame(0.0, $rows[0]['convertedValues']['EUR']['expense']);
    }

    public function testGetActualsByCategoryForPeriodAggregatesByCategoryAndCurrency(): void
    {
        $prefix = 'repo-actuals-'.uniqid('', true);

        $income = $this->createIncome(200, $prefix, CarbonImmutable::parse('2026-01-12 09:00:00'), $this->eurAccount, 200);
        $expense = $this->createExpense(50, $prefix, CarbonImmutable::parse('2026-01-12 10:00:00'), $this->eurAccount, 50);

        $rows = $this->repo->getActualsByCategoryForPeriod(
            CarbonImmutable::parse('2026-01-12 00:00:00'),
            CarbonImmutable::parse('2026-01-12 23:59:59')
        );

        $incomeRow = $this->findCategoryCurrencyRow($rows, $income->getCategory()->getId(), 'EUR');
        $expenseRow = $this->findCategoryCurrencyRow($rows, $expense->getCategory()->getId(), 'EUR');

        self::assertNotNull($incomeRow);
        self::assertNotNull($expenseRow);

        self::assertSame(200.0, $incomeRow['income']);
        self::assertSame(0.0, $incomeRow['expense']);

        self::assertSame(0.0, $expenseRow['income']);
        self::assertSame(50.0, $expenseRow['expense']);
    }

    public function testGetActualsByCategoryForPeriodExcludesNonAffectingProfitCategories(): void
    {
        $prefix = 'repo-actuals-profit-'.uniqid('', true);

        $excludedCategory = $this->em->getRepository(ExpenseCategory::class)->findOneBy(['isAffectingProfit' => false]);
        self::assertInstanceOf(ExpenseCategory::class, $excludedCategory);

        $this->createExpense(
            amount: 77,
            note: $prefix,
            executedAt: CarbonImmutable::parse('2026-01-13 08:00:00'),
            account: $this->eurAccount,
            convertedEur: 77,
            category: $excludedCategory,
        );

        $rows = $this->repo->getActualsByCategoryForPeriod(
            CarbonImmutable::parse('2026-01-13 00:00:00'),
            CarbonImmutable::parse('2026-01-13 23:59:59')
        );

        $excludedRow = $this->findCategoryCurrencyRow($rows, $excludedCategory->getId(), 'EUR');
        self::assertNull($excludedRow);
    }

    private function createExpense(
        float $amount,
        string $note,
        CarbonImmutable $executedAt,
        Account $account,
        float $convertedEur,
        ?ExpenseCategory $category = null,
    ): Expense {
        $category ??= $this->expenseCategory;

        $expense = (new Expense())
            ->setOwner($this->owner)
            ->setAccount($account)
            ->setCategory($category)
            ->setAmount($amount)
            ->setNote($note)
            ->setExecutedAt($executedAt)
            ->setConvertedValues([
                'EUR' => $convertedEur,
                'USD' => $convertedEur * 1.1,
            ]);

        $this->em->persist($expense);
        $this->em->flush();
        $this->createdTransactionIds[] = $expense->getId();

        return $expense;
    }

    private function createIncome(
        float $amount,
        string $note,
        CarbonImmutable $executedAt,
        Account $account,
        float $convertedEur,
    ): Income {
        $income = (new Income())
            ->setOwner($this->owner)
            ->setAccount($account)
            ->setCategory($this->incomeCategory)
            ->setAmount($amount)
            ->setNote($note)
            ->setExecutedAt($executedAt)
            ->setConvertedValues([
                'EUR' => $convertedEur,
                'USD' => $convertedEur * 1.1,
            ]);

        $this->em->persist($income);
        $this->em->flush();
        $this->createdTransactionIds[] = $income->getId();

        return $income;
    }

    private function findCategoryCurrencyRow(array $rows, int $categoryId, string $currency): ?array
    {
        foreach ($rows as $row) {
            if (($row['categoryId'] ?? null) !== $categoryId) {
                continue;
            }

            $values = $row['convertedValues'][$currency] ?? null;
            if ($values !== null) {
                return $values;
            }
        }

        return null;
    }
}
