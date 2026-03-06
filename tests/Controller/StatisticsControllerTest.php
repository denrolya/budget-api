<?php

namespace App\Tests\Controller;

use App\Tests\BaseApiTestCase;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class StatisticsControllerTest extends BaseApiTestCase
{
    private const VALUE_BY_PERIOD_URL = '/api/v2/statistics/value-by-period';
    private const CATEGORY_TREE_URL = '/api/v2/statistics/category/tree';

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

    /**
     * Jan 2021 expense categories:
     *   Food & Drinks (root): value=0, total=1130, children=[Groceries(800), EatingOut(330)]
     *   Rent: value=400, total=400
     * Jan 2021 income categories:
     *   Salary: value=1800
     *   Bonus: value=200
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
