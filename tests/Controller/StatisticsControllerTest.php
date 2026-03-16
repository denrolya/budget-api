<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\BaseApiTestCase;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * API contract tests for Statistics endpoints.
 *
 * Endpoints covered:
 *   GET /api/v2/statistics/value-by-period      — income/expense totals per time bucket
 *   GET /api/v2/statistics/category/tree         — category tree with aggregated values
 *   GET /api/v2/statistics/category/timeline     — per-category timeline
 *   GET /api/v2/statistics/account-distribution  — expense/income distribution by account
 *   GET /api/v2/statistics/by-weekdays           — aggregation by day of week
 *   GET /api/v2/statistics/top-value-category    — top category by value
 *   GET /api/v2/statistics/avg                   — average per interval
 *   GET /api/v2/statistics/daily                 — daily transaction counts
 *
 * Fixtures: BaseApiTestCase (shared accounts, categories, transactions)
 */
class StatisticsControllerTest extends BaseApiTestCase
{
    // ──────────────────────────────────────────────────────────────────────
    //  value-by-period — intervals and date ranges
    // ──────────────────────────────────────────────────────────────────────

    private const VALUE_BY_PERIOD_URL = '/api/v2/statistics/value-by-period';
    private const CATEGORY_TREE_URL = '/api/v2/statistics/category/tree';

    /**
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithoutArguments(): void
    {
        $response = $this->client->request(Request::METHOD_GET, self::VALUE_BY_PERIOD_URL);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertArrayHasKey('before', $content[0]);
        self::assertArrayHasKey('after', $content[0]);
        self::assertArrayHasKey('expense', $content[0]);
        self::assertArrayHasKey('income', $content[0]);

        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay(CarbonImmutable::now()->startOfMonth())
        );
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay(CarbonImmutable::now()->endOfMonth())
        );
        self::assertIsNumeric($content[0]['before']);
        self::assertIsNumeric($content[0]['after']);
        self::assertIsNumeric($content[0]['expense']);
        self::assertIsNumeric($content[0]['income']);
    }

    /**
     * Jan 2021: 30 EUR Cash expenses (1480 EUR) + 5 UAH Card expenses (50 EUR) = 1530 EUR expense
     *           4 incomes: 100+200+500+1200 = 2000 EUR income
     * Jan 2020: 1 expense (800 EUR Groceries) + 1 income (2000 EUR Salary)
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithBeforeAndAfter(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertArrayHasKey('before', $content[0]);
        self::assertArrayHasKey('after', $content[0]);
        self::assertArrayHasKey('expense', $content[0]);
        self::assertArrayHasKey('income', $content[0]);
        self::assertEqualsWithDelta(1530, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2000, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));

        $after = CarbonImmutable::parse('2020-01-01');
        $before = CarbonImmutable::parse('2020-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(800, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2000, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
    }

    /**
     * Feb 01 2021: expense=100 (Groceries), income=200 (Salary)
     * Mar 31 2021: expense=150 (Rent), income=0
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithOneDayInterval(): void
    {
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-03-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 day',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(59, $content);

        self::assertEqualsWithDelta(100, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(200, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after));

        self::assertEqualsWithDelta(150, $content[58]['expense'], 0.01);
        self::assertEqualsWithDelta(0, $content[58]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[58]['after'])->isSameDay($before));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[58]['before'])->isSameDay($before));

        // Single day
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-02-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 day',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(100, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(200, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
    }

    /**
     * Week 1 (Feb 01-07): expense=450, income=450
     * Week 9 (Mar 29-31): expense=250, income=500
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithOneWeekInterval(): void
    {
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-03-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 week',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(9, $content);

        self::assertEqualsWithDelta(450, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(450, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->endOfWeek()));

        self::assertEqualsWithDelta(250, $content[8]['expense'], 0.01);
        self::assertEqualsWithDelta(500, $content[8]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[8]['after'])->isSameDay($before->startOfWeek()));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[8]['before'])->isSameDay($before->endOfDay()));

        // Single day with 1-day interval
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-02-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 day',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(100, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(200, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
    }

    /**
     * Jan 2021: expense=1530, income=2000
     * Dec 2021: expense=1500 (Groceries600+EatingOut400+Rent500), income=500 (Bonus)
     * Jan 01 single day: expense=50 (Groceries EUR Cash), income=100 (Salary)
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithOneMonthInterval(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 month',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(12, $content);

        self::assertEqualsWithDelta(1530, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2000, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->endOfMonth()));

        self::assertEqualsWithDelta(1500, $content[11]['expense'], 0.01);
        self::assertEqualsWithDelta(500, $content[11]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[11]['after'])->isSameDay($before->startOfMonth()));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[11]['before'])->isSameDay($before));

        // Single day
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 day',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(50, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(100, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
    }

    /**
     * Q1 (Jan-Mar): expense=5060, income=5300
     * Q4 (Oct-Dec): expense=3850, income=4300
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithCustomInterval(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '3 months',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(4, $content);

        self::assertEqualsWithDelta(5060, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(5300, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->addMonths(3)->subDay())
        );

        self::assertEqualsWithDelta(3850, $content[3]['expense'], 0.01);
        self::assertEqualsWithDelta(4300, $content[3]['income'], 0.01);
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[3]['after'])->isSameDay($before->subMonths(3))
        );
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[3]['before'])->isSameDay($before));

        // Single day
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL('/api/v2/statistics/value-by-period', [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '1 day',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(50, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(100, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
    }

    /**
     * Full year 2021: expense=13610 (incl. 200 debt expense in Jun), income=17600
     * interval=true (auto): 61 periods, period[0] = Jan01-Jan07
     *   Jan01-07 expense=390 (360 EUR Cash + 30 UAH), income=300 (Salary100+Bonus200)
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithBooleanInterval(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');

        // interval=false → single period
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => 'false',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(13410, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(17600, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));

        // interval=true → auto-partitioned periods
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => 'true',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(61, $content);
        self::assertEqualsWithDelta(390, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(300, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay(CarbonImmutable::parse('2021-01-07'))
        );

        // interval='' → same as false (single period)
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'interval' => '',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(13410, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(17600, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
    }

    /**
     * @covers \App\Controller\StatisticsController::value
     *
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testValueByPeriodWithWrongBeforeAndAfter(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2020-01-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
            ])
        );
        self::assertResponseIsSuccessful();
        self::assertEmpty($response->toArray());

        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, ['interval' => 'galaxy far far away'])
        );
        self::assertResponseStatusCodeSame(400);

        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-01',
                'interval' => 'galaxy far far away',
            ])
        );
        self::assertResponseStatusCodeSame(400);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  category/tree
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Jan 2021 expense categories:
     *   Food & Drinks (root): value=0, total=1130, children=[Groceries(800), EatingOut(330)]
     *   Rent: value=400, total=400
     * Jan 2021 income categories:
     *   Salary: value=1800
     *   Bonus: value=200
     *
     * @covers \App\Controller\StatisticsController::categoryTree
     */
    public function testCategoryTreeWithBeforeAndAfter(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::CATEGORY_TREE_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // Only categories with non-zero transactions are returned
        // Jan 2021: Food & Drinks (expense) + Rent (expense) + Salary (income) + Bonus (income)
        self::assertCount(4, $content);

        $foodAndDrinks = $this->findByName($content, 'Food & Drinks');
        self::assertNotNull($foodAndDrinks);
        self::assertEqualsWithDelta(0, $foodAndDrinks['value'], 0.01);
        self::assertEqualsWithDelta(1130, $foodAndDrinks['total'], 0.01);
        self::assertCount(2, $foodAndDrinks['children']);

        $rent = $this->findByName($content, 'Rent');
        self::assertNotNull($rent);
        self::assertEqualsWithDelta(400, $rent['value'], 0.01);
        self::assertEqualsWithDelta(400, $rent['total'], 0.01);

        $salary = $this->findByName($content, 'Salary');
        self::assertNotNull($salary);
        self::assertEqualsWithDelta(1800, $salary['value'], 0.01);

        $bonus = $this->findByName($content, 'Bonus');
        self::assertNotNull($bonus);
        self::assertEqualsWithDelta(200, $bonus['value'], 0.01);
    }

    /**
     * @covers \App\Controller\StatisticsController::categoryTree
     */
    public function testCategoryTreeWithBeforeAfterAndType(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');

        // Expense tree only
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::CATEGORY_TREE_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // Only Food & Drinks and Rent have Jan 2021 expenses
        self::assertCount(2, $content);

        $foodAndDrinks = $this->findByName($content, 'Food & Drinks');
        self::assertNotNull($foodAndDrinks);
        self::assertEqualsWithDelta(0, $foodAndDrinks['value'], 0.01);
        self::assertEqualsWithDelta(1130, $foodAndDrinks['total'], 0.01);
        self::assertCount(2, $foodAndDrinks['children']);
        self::assertEqualsWithDelta(1130, array_sum(array_column($foodAndDrinks['children'], 'total')), 0.01);

        // Income tree only
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::CATEGORY_TREE_URL, [
                'after' => $after->toDateString(),
                'before' => $before->toDateString(),
                'type' => 'income',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // Only Salary and Bonus have Jan 2021 incomes
        self::assertCount(2, $content);

        $salary = $this->findByName($content, 'Salary');
        self::assertNotNull($salary);
        self::assertEqualsWithDelta(1800, $salary['value'], 0.01);
        self::assertEqualsWithDelta(1800, $salary['total'], 0.01);

        $bonus = $this->findByName($content, 'Bonus');
        self::assertNotNull($bonus);
        self::assertEqualsWithDelta(200, $bonus['value'], 0.01);
        self::assertEqualsWithDelta(200, $bonus['total'], 0.01);

        // Total income = 2000
        self::assertEqualsWithDelta(2000, array_sum(array_column($content, 'total')), 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  category/timeline
    // ──────────────────────────────────────────────────────────────────────

    private const CATEGORY_TIMELINE_URL = '/api/v2/statistics/category/timeline';

    /**
     * Regression: `$categories !== []` was true for null, so omitting the categories param
     * caused getCategoriesWithDescendantsByType(null) to be called, which ran unnecessary
     * queries and returned [] (no categories), breaking the transaction filter.
     * The endpoint must return 200 and a valid response structure when no categories are given.
     *
     * @covers \App\Controller\StatisticsController::categoryTimeline
     */
    public function testCategoryTimelineWithoutCategoriesDoesNotCrash(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::CATEGORY_TIMELINE_URL, [
                'after'  => '2021-01-01',
                'before' => '2021-01-31',
            ])
        );

        self::assertResponseIsSuccessful();
        // Without a categories filter the endpoint returns null (no timeline to show).
        // The response body is empty — just assert the request did not crash.
    }

    /**
     * With a valid category filter the timeline must include that category key.
     *
     * @covers \App\Controller\StatisticsController::categoryTimeline
     */
    public function testCategoryTimelineWithCategoryFilterReturnsCategoryData(): void
    {
        $groceries = $this->em->getRepository(\App\Entity\ExpenseCategory::class)->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        self::assertNotNull($groceries);

        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::CATEGORY_TIMELINE_URL, [
                'after'      => '2021-01-01',
                'before'     => '2021-01-31',
                'categories' => [$groceries->getId()],
            ])
        );

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertIsArray($content);
        self::assertNotEmpty($content, 'Timeline must contain at least one category entry.');
        // Each entry should have a name key corresponding to a category name
        foreach ($content as $categoryName => $periods) {
            self::assertIsString($categoryName);
            self::assertIsArray($periods);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  value-by-period — category filter edge cases
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Regression: when categories[] contains IDs that do not exist, getCategoriesWithDescendantsByType()
     * returns [] and the old code passed [] to getList(), which dropped the filter entirely and
     * returned ALL transactions. The fix uses [0] as an impossible sentinel so zero results are returned.
     *
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriodWithNonExistentCategoryReturnsZero(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after'       => '2021-01-01',
                'before'      => '2021-01-31',
                'categories'  => [999999],
            ])
        );

        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(0.0, $content[0]['expense'], 0.001, 'Non-existent category must yield zero expense, not all transactions.');
        self::assertEqualsWithDelta(0.0, $content[0]['income'],  0.001, 'Non-existent category must yield zero income, not all transactions.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  value-by-period — type filter
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriod_typeExpense_returnsOnlyExpenseValues(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertGreaterThan(0, $content[0]['expense']);
        self::assertEqualsWithDelta(0, $content[0]['income'], 0.01, 'Income must be zero with type=expense.');
    }

    /**
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriod_typeIncome_returnsOnlyIncomeValues(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'type' => 'income',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(0, $content[0]['expense'], 0.01, 'Expense must be zero with type=income.');
        self::assertGreaterThan(0, $content[0]['income']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  value-by-period — accounts filter
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriod_accountsFilter_restrictsToAccount(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'accounts' => [$this->accountCashEUR->getId()],
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        // EUR Cash only: 30 expenses = 1480 EUR, 4 incomes = 2000 EUR
        self::assertEqualsWithDelta(1480, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2000, $content[0]['income'], 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  value-by-period — empty date range
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriod_emptyRange_returnsZeros(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::VALUE_BY_PERIOD_URL, [
                'after' => '2025-06-01',
                'before' => '2025-06-30',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(0, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(0, $content[0]['income'], 0.01);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  daily stats
    // ──────────────────────────────────────────────────────────────────────

    private const DAILY_URL = '/api/v2/statistics/daily';

    /**
     * @covers \App\Controller\StatisticsController::dailyStats
     */
    public function testDaily_returnsCorrectShape(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::DAILY_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
        self::assertNotEmpty($content['data']);

        $firstDay = $content['data'][0];
        self::assertArrayHasKey('day', $firstDay);
        self::assertArrayHasKey('count', $firstDay);
        self::assertArrayHasKey('convertedValues', $firstDay);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  avg
    // ──────────────────────────────────────────────────────────────────────

    private const AVG_URL = '/api/v2/statistics/avg';

    /**
     * @covers \App\Controller\StatisticsController::average
     */
    public function testAvg_returnsCorrectShape(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::AVG_URL, [
                'after' => '2021-01-01',
                'before' => '2021-12-31',
                'interval' => '1 month',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        // Returns array of {date, value} per period bucket
        self::assertIsArray($content);
        self::assertNotEmpty($content);
        $first = $content[0];
        self::assertArrayHasKey('date', $first);
        self::assertArrayHasKey('value', $first);
        self::assertIsNumeric($first['date']);
        self::assertIsNumeric($first['value']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  account-distribution
    // ──────────────────────────────────────────────────────────────────────

    private const ACCOUNT_DISTRIBUTION_URL = '/api/v2/statistics/account-distribution';

    /**
     * @covers \App\Controller\StatisticsController::accountDistribution
     */
    public function testAccountDistribution_returnsCorrectShape(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::ACCOUNT_DISTRIBUTION_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertNotEmpty($content);

        $firstEntry = $content[0];
        self::assertArrayHasKey('amount', $firstEntry);
        self::assertArrayHasKey('value', $firstEntry);
        self::assertArrayHasKey('account', $firstEntry);
        self::assertArrayHasKey('id', $firstEntry['account']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  daily stats — with filters
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::dailyStats
     */
    public function testDaily_withTypeFilter_returnsFilteredData(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::DAILY_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
    }

    /**
     * @covers \App\Controller\StatisticsController::dailyStats
     */
    public function testDaily_withAccountsFilter_restrictsResults(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::DAILY_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'accounts' => [$this->accountCashEUR->getId()],
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
    }

    /**
     * @covers \App\Controller\StatisticsController::dailyStats
     */
    public function testDaily_emptyRange_returnsEmptyData(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::DAILY_URL, [
                'after' => '2099-01-01',
                'before' => '2099-12-31',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertArrayHasKey('data', $content);
        self::assertEmpty($content['data']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  avg — with filters
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::average
     */
    public function testAvg_withTypeFilter_returnsFilteredAverage(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::AVG_URL, [
                'after' => '2021-01-01',
                'before' => '2021-12-31',
                'interval' => '1 month',
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertNotEmpty($content);
        $first = $content[0];
        self::assertArrayHasKey('date', $first);
        self::assertArrayHasKey('value', $first);
    }

    /**
     * @covers \App\Controller\StatisticsController::average
     */
    public function testAvg_withAccountsFilter_restrictsResults(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::AVG_URL, [
                'after' => '2021-01-01',
                'before' => '2021-12-31',
                'interval' => '1 month',
                'accounts' => [$this->accountCashEUR->getId()],
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertNotEmpty($content);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  account-distribution — income type
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::accountDistribution
     */
    public function testAccountDistribution_incomeType_returnsDistribution(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::ACCOUNT_DISTRIBUTION_URL, [
                'after' => '2021-01-01',
                'before' => '2021-01-31',
                'type' => 'income',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertNotEmpty($content);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\StatisticsController::categoryTree
     */
    public function testCategoryTree_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::CATEGORY_TREE_URL);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\StatisticsController::accountDistribution
     */
    public function testAccountDistribution_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::ACCOUNT_DISTRIBUTION_URL);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\StatisticsController::average
     */
    public function testAvg_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::AVG_URL);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\StatisticsController::value
     */
    public function testValueByPeriod_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::VALUE_BY_PERIOD_URL);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\StatisticsController::dailyStats
     */
    public function testDaily_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::DAILY_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  by-weekdays
    // ──────────────────────────────────────────────────────────────────────

    private const BY_WEEKDAYS_URL = '/api/v2/statistics/by-weekdays';

    /**
     * @covers \App\Controller\StatisticsController::transactionsValueByWeekdays
     */
    public function testByWeekdays_returnsCorrectShape(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::BY_WEEKDAYS_URL, [
                'after' => '2021-01-01',
                'before' => '2021-12-31',
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertNotEmpty($content, 'Weekday aggregation must not be empty for a year with expenses.');
    }

    /**
     * @covers \App\Controller\StatisticsController::transactionsValueByWeekdays
     */
    public function testByWeekdays_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::BY_WEEKDAYS_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  top-value-category
    // ──────────────────────────────────────────────────────────────────────

    private const TOP_VALUE_CATEGORY_URL = '/api/v2/statistics/top-value-category';

    /**
     * @covers \App\Controller\StatisticsController::topValueCategory
     */
    public function testTopValueCategory_returnsCorrectShape(): void
    {
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(self::TOP_VALUE_CATEGORY_URL, [
                'after' => '2021-01-01',
                'before' => '2021-12-31',
                'type' => 'expense',
            ])
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertIsArray($content);
        self::assertNotEmpty($content, 'Top value category must not be empty for a year with expenses.');
    }

    /**
     * @covers \App\Controller\StatisticsController::topValueCategory
     */
    public function testTopValueCategory_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::TOP_VALUE_CATEGORY_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  HELPERS
    // ──────────────────────────────────────────────────────────────────────

    private function findByName(array $items, string $name): ?array
    {
        foreach ($items as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }
        return null;
    }
}
