<?php

namespace App\Tests\Controller;

use App\Tests\BaseApiTest;
use Carbon\CarbonImmutable;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class StatisticsControllerTest extends BaseApiTest
{
    private const VALUE_BY_PERIOD_URL = '/api/v2/statistics/value-by-period';

    public function testValueByPeriodWithoutArguments(): void
    {
        $response = $this->client->request('GET', self::VALUE_BY_PERIOD_URL);
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
    }

    /**
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function testValueByPeriodWithGivenBeforeAndAfter(): void
    {
        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-31');
        $response = $this->client->request(
            'GET',
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

        $after = CarbonImmutable::parse('2020-01-01');
        $before = CarbonImmutable::parse('2020-01-31');
        $response = $this->client->request(
            'GET',
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
    }

    public function testValueByPeriodWithOneDayInterval(): void
    {
        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-03-31');
        $response = $this->client->request(
            'GET',
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

        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-02-01');
        $response = $this->client->request(
            'GET',
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
            'GET',
            $this->buildURL(
                '/api/v2/statistics/value-by-period',
                ['after' => $after->toDateString(), 'before' => $before->toDateString(), 'interval' => '1 week']
            )
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertCount(9, $content);

        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['after'])->isSameDay($after));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[0]['before'])->isSameDay($after->endOfWeek()));
        self::assertEqualsWithDelta(238.06, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(261.14, $content[0]['income'], 0.01);

        self::assertTrue(CarbonImmutable::createFromTimestamp($content[8]['after'])->isSameDay($before->startOfWeek()));
        self::assertTrue(CarbonImmutable::createFromTimestamp($content[8]['before'])->isSameDay($before->endOfDay()));
        self::assertEqualsWithDelta(229.26, $content[8]['expense'], 0.01);
        self::assertEqualsWithDelta(5255, $content[8]['income'], 0.01);

        $after = CarbonImmutable::parse('2021-02-01');
        $before = CarbonImmutable::parse('2021-02-01');
        $response = $this->client->request(
            'GET',
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
            'GET',
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

        $after = CarbonImmutable::parse('2021-01-01');
        $before = CarbonImmutable::parse('2021-01-01');
        $response = $this->client->request(
            'GET',
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
        self::assertEqualsWithDelta(22.13, $content[0]['expense'], 0.01);
        self::assertEqualsWithDelta(2.64, $content[0]['income'], 0.01);
    }
}
