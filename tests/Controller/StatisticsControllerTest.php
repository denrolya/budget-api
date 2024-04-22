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

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
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
            CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay(
                CarbonImmutable::now()->startOfMonth()
            )
        );
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay(CarbonImmutable::now()->endOfMonth())
        );
        $this->assertMatchesSnapshot($content);
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function testValueByPeriodWithBeforeAndAfter(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::VALUE_BY_PERIOD_URL,
                ['after' => $after->toDateString(), 'before' => $before->toDateString()]
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertArrayHasKey('before', $content[0]);
        self::assertArrayHasKey('after', $content[0]);
        self::assertArrayHasKey('expense', $content[0]);
        self::assertArrayHasKey('income', $content[0]);

        self::assertEqualsWithDelta(1514.2, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(6807.44, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2020-01-01');
        $before = CarbonImmutable::parse('2020-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::VALUE_BY_PERIOD_URL,
                ['after' => $after->toDateString(), 'before' => $before->toDateString()]
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertArrayHasKey('before', $content[0]);
        self::assertArrayHasKey('after', $content[0]);
        self::assertArrayHasKey('expense', $content[0]);
        self::assertArrayHasKey('income', $content[0]);

        self::assertEqualsWithDelta(1432.09, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(9320, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);
    }

    public function testValueByPeriodWithOneDayInterval(): void
    {
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-03-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 day']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(59, $content);

        self::assertEqualsWithDelta(69.66, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(54.42, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after));

        self::assertEqualsWithDelta(46.22, $content[58]['expense'], 0.01);
        self::assertEqualsWithDelta(0, $content[58]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[58]['after'])->isSameDay($before));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[58]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-02-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 day']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        self::assertEqualsWithDelta(69.66, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(54.42, $content[0]['income'], 0.01);
        $this->assertMatchesSnapshot($content);
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function testValueByPeriodWithOneWeekInterval(): void
    {
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-03-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 week']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(9, $content);

        self::assertEqualsWithDelta(238.06, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(261.14, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->endOfWeek()));

        self::assertEqualsWithDelta(229.26, $content[8]['expense'], 0.01);
        self::assertEqualsWithDelta(5255, $content[8]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[8]['after'])->isSameDay($before->startOfWeek()));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[8]['before'])->isSameDay($before->endOfDay()));
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-02-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 day']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertEqualsWithDelta(69.66, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(54.42, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function testValueByPeriodWithOneMonthInterval(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 month']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(12, $content);

        self::assertEqualsWithDelta(1514.2, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(6807.44, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->endOfMonth()));

        self::assertEqualsWithDelta(2326.12, $content[11]['expense'], 0.01);
        self::assertEqualsWithDelta(5.75, $content[11]['income'], 0.01);
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[11]['after'])->isSameDay($before->startOfMonth())
        );
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[11]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 day']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertEqualsWithDelta(22.13, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2.64, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);
    }

    public function testValueByPeriodWithCustomInterval(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '3 months']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(4, $content);

        self::assertEqualsWithDelta(44609.09, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(18888.04, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->addMonths(3)->subDay())
        );

        self::assertEqualsWithDelta(7623.7, $content[3]['expense'], 0.01);
        self::assertEqualsWithDelta(22299.48, $content[3]['income'], 0.01);
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[3]['after'])->isSameDay($before->subMonths(3))
        );
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[3]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-01');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 day']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertEqualsWithDelta(22.13, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2.64, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);
    }

    public function testValueByPeriodWithBooleanInterval(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => 'false']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);

        self::assertEqualsWithDelta(69677.38, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(73306.92, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => 'true']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(61, $content);

        self::assertEqualsWithDelta(151.33, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2.64, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(
            CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay(CarbonImmutable::parse('2021-01-07'))
        );
        $this->assertMatchesSnapshot($content);

        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-12-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(1, $content);
        self::assertEqualsWithDelta(69677.38, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(73306.92, $content[0]['income'], 0.01);
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($before));
        $this->assertMatchesSnapshot($content);
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
            $this->buildURL(
                self::VALUE_BY_PERIOD_URL,
                ['after' => $after->toDateString(), 'before' => $before->toDateString()]
            )
        );
        self::assertResponseIsSuccessful();
        self::assertEmpty($response->toArray());

        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::VALUE_BY_PERIOD_URL,
                ['interval' => 'galaxy far far away']
            )
        );
        self::assertResponseStatusCodeSame(400);

        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::VALUE_BY_PERIOD_URL,
                ['after' => '2021-01-01', 'before' => '2021-01-01', 'interval' => 'galaxy far far away']
            )
        );
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testCategoryTreeWithBeforeAndAfter(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::CATEGORY_TREE_URL,
                ['after' => $after->toDateString(), 'before' => $before->toDateString()]
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(18, $content);

        self::assertEquals(0, $content[0]['value']);
        self::assertEqualsWithDelta(326.255, $content[0]['total'], 0.01);
        self::assertCount(9, $content[0]['children']);
        self::assertEqualsWithDelta(3.22, $content[0]['children'][0]['value'], 0.01);
        self::assertEqualsWithDelta(3.22, $content[0]['children'][0]['total'], 0.01);
        self::assertEqualsWithDelta(17.9, $content[0]['children'][8]['value'], 0.01);
        self::assertEqualsWithDelta(25.71, $content[0]['children'][8]['total'], 0.01);

        self::assertEquals(0, $content[16]['value']);
        self::assertEqualsWithDelta(142.31, $content[16]['total'], 0.01);
        self::assertCount(7, $content[16]['children']);
        self::assertEqualsWithDelta(0, $content[16]['children'][1]['value'], 0.01);
        self::assertEqualsWithDelta(119.56, $content[16]['children'][1]['total'], 0.01);
        self::assertCount(2, $content[16]['children'][1]['children']);
        $this->assertMatchesSnapshot($content);
    }

    /**
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function testCategoryTreeWithBeforeAfterAndType(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');
        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::CATEGORY_TREE_URL,
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'type' => 'expense']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(10, $content);
        self::assertEqualsWithDelta(0, $content[0]['value'], 0.01);
        self::assertEqualsWithDelta(326.255, $content[0]['total'], 0.01);
        self::assertCount(9, $content[0]['children']);
        self::assertEqualsWithDelta(326.25, array_sum(array_column($content['0']['children'], 'total')), 0.01);
        $this->assertMatchesSnapshot($content);

        $response = $this->client->request(
            Request::METHOD_GET,
            $this->buildURL(
                self::CATEGORY_TREE_URL,
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'type' => 'income']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(8, $content);
        self::assertEqualsWithDelta(0, $content[0]['value'], 0.01);
        self::assertEqualsWithDelta(4.61, $content[0]['total'], 0.01);
        self::assertCount(4, $content[0]['children']);
        self::assertEqualsWithDelta(4.61, array_sum(array_column($content['0']['children'], 'value')), 0.01);
        $this->assertMatchesSnapshot($content);
    }
}
