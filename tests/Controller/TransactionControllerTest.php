<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Tests\BaseApiTest;
use Carbon\Carbon;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class TransactionControllerTest extends BaseApiTest
{
    /**
     * @group smoke
     * @group transactions
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testAuthorizedUserCanAccessListOfTransactions(): void
    {
        $this->client->request('GET', '/api/v2/transaction', ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);

        $response = $this->client->request('GET', '/api/v2/transaction');
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('list', $content);
        self::assertArrayHasKey('totalValue', $content);
        self::assertArrayHasKey('count', $content);
    }

    /**
     * @group smoke
     * @group transactions
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function testTransactionsListPagination(): void
    {
        $response = $this->client->request('GET', '/api/v2/transaction');
        $content = $response->toArray();
        $totalValue = $content['totalValue'];
        $count = $content['count'];

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=1');
        $content = $response->toArray();
        self::assertCount(1, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=1&page=2');
        $content = $response->toArray();
        self::assertCount(1, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=2&page=2');
        $content = $response->toArray();
        self::assertCount(2, $content['list']);
        self::assertEquals($totalValue, $content['totalValue']);
        self::assertEquals($count, $content['count']);

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=0');
        $content = $response->toArray();
        self::assertCount($count, $content['list']);

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=1&page=100');
        $content = $response->toArray();
        self::assertCount(0, $content['list']);

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=100&page=1');
        $content = $response->toArray();
        self::assertCount($count, $content['list']);

        $response = $this->client->request('GET', '/api/v2/transaction?perPage=100&page=2');
        $content = $response->toArray();
        self::assertCount(0, $content['list']);

        $response = $this->client->request('GET', '/api/v2/transaction?page=0');
        $content = $response->toArray();
        self::assertCount($count, $content['list']);
    }

    /**
     * @group smoke
     * @group transactions
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBeforeAfterFilters(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}"
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(124, $content['count']);
        self::assertEqualsWithDelta(5525.58, $content['totalValue'], 0.01);
        self::assertTrue(Carbon::parse($content['list'][0]['executedAt'])->isBetween($after, $before));
        self::assertTrue(Carbon::parse($content['list'][29]['executedAt'])->isBetween($after, $before));


        $lastPageNumber = ceil($content['count'] / 30);
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&page={$lastPageNumber}",
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertCount(4, $content['list']);
        self::assertEquals(124, $content['count']);
        self::assertEqualsWithDelta(5525.58, $content['totalValue'], 0.01);
        self::assertTrue(Carbon::parse($content['list'][0]['executedAt'])->isBetween($after, $before));
        self::assertTrue(Carbon::parse($content['list'][3]['executedAt'])->isBetween($after, $before));
    }

    public function testAccountsFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&accounts[]=10",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(80, $content['count']);
        self::assertEqualsWithDelta(-953.88, $content['totalValue'], 0.01);
        self::assertEquals(10, $content['list'][0]['account']['id']);
        self::assertEquals(10, $content['list'][29]['account']['id']);

        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&accounts[]=10&accounts[]=4",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(119, $content['count']);
        self::assertEqualsWithDelta(-1300.9, $content['totalValue'], 0.01);
        self::assertContains($content['list'][0]['account']['id'], [10, 4]);
        self::assertContains($content['list'][29]['account']['id'], [10, 4]);

        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&accounts[]=100&accounts[]=xsss&accounts[]=-1",
        );
        self::assertResponseStatusCodeSame(200);

        $content = $response->toArray();
        self::assertCount(0, $content['list']);
        self::assertEquals(0, $content['count']);
        self::assertEquals(0, $content['totalValue']);
    }

    public function testCategoriesFilter(): void
    {
        $testCategories = [1];
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&categories[]=1",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(46, $content['count']);
        self::assertEqualsWithDelta(-326.26, $content['totalValue'], 0.01);

        $nestedCategoriesIds = array_map(
            static fn(Category $category) => $category->getDescendantsFlat()->map(
                fn(Category $category) => $category->getId()
            ),
            $this->em->getRepository(Category::class)->findBy(['id' => $testCategories])
        )[0]->toArray();
        self::assertContains($content['list'][0]['category']['id'], $nestedCategoriesIds);
        self::assertContains($content['list'][29]['category']['id'], $nestedCategoriesIds);

        $testCategories = [1, 2];
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&categories[]=1&categories[]=2",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertCount(30, $content['list']);
        self::assertEquals(47, $content['count']);
        self::assertEqualsWithDelta(-502.4, $content['totalValue'], 0.01);
        $nestedCategoriesIds = array_map(
            static fn(Category $category) => $category->getDescendantsFlat()->map(
                fn(Category $category) => $category->getId()
            ),
            $this->em->getRepository(Category::class)->findBy(['id' => $testCategories])
        )[0]->toArray();
        self::assertContains($content['list'][0]['category']['id'], $nestedCategoriesIds);
        self::assertContains($content['list'][29]['category']['id'], $nestedCategoriesIds);

        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&categories[]=100&categories[]=xsss&categories[]=-1",
        );
        self::assertResponseStatusCodeSame(200);

        $content = $response->toArray();
        self::assertCount(0, $content['list']);

    }

    public function testWithNestedCategoriesFilter(): void
    {
        $category = $this->em->getRepository(Category::class)->find(1);
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2022-01-31')->endOfDay();
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&categories[]=1&withNestedCategories=1",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(790, $content['count']);
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(-5179.92, $content['totalValue'], 0.01);

        $nestedCategoriesIds = $category->getDescendantsFlat()->map(fn(Category $category) => $category->getId()
        )->toArray();
        self::assertContains($content['list'][0]['category']['id'], $nestedCategoriesIds);
        self::assertContains($content['list'][14]['category']['id'], $nestedCategoriesIds);
        self::assertContains($content['list'][29]['category']['id'], $nestedCategoriesIds);

        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&categories[]=1&withNestedCategories=0",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(2, $content['count']);
        self::assertCount(2, $content['list']);
        self::assertEqualsWithDelta(-1.62, $content['totalValue'], 0.01);
        self::assertEquals(1, $content['list'][0]['category']['id']);
        self::assertEquals(1, $content['list'][1]['category']['id']);
    }

    public function testIsDraftFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&isDraft=1",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(0, $content['count']);
        self::assertCount(0, $content['list']);

        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&isDraft=0",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(124, $content['count']);
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(5525.58, $content['totalValue'], 0.01);
    }

    public function testTypeFilter(): void
    {
        $after = Carbon::parse('2021-01-01')->startOfDay();
        $before = Carbon::parse('2021-01-31')->endOfDay();
        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&type=expense",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(107, $content['count']);
        self::assertCount(30, $content['list']);
        self::assertEqualsWithDelta(-1514.2, $content['totalValue'], 0.01);

        $response = $this->client->request(
            'GET',
            "/api/v2/transaction?after={$after->toDateString()}&before={$before->toDateString()}&type=income",
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(17, $content['count']);
        self::assertCount(17, $content['list']);
        self::assertEqualsWithDelta(7039.79, $content['totalValue'], 0.01);
    }
}
