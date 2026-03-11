<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\BaseApiTestCase;

class AccountControllerTest extends BaseApiTestCase
{
    private const ACCOUNT_URL = '/api/accounts';

    public function testCanCreateAccountWithIntegerBalance(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'API Integer Balance Account',
                'currency' => 'EUR',
                'balance' => 123,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertSame('API Integer Balance Account', $content['name']);
        self::assertSame('EUR', $content['currency']);
        self::assertIsNumeric($content['balance']);
        self::assertEqualsWithDelta(123.0, (float) $content['balance'], 0.000001);
    }

    public function testCanCreateAccountWithFloatBalance(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'API Float Balance Account',
                'currency' => 'USD',
                'balance' => 123.45,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertSame('API Float Balance Account', $content['name']);
        self::assertSame('USD', $content['currency']);
        self::assertIsNumeric($content['balance']);
        self::assertEqualsWithDelta(123.45, (float) $content['balance'], 0.000001);
    }
}
