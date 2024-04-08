<?php

namespace App\Tests\Controller;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Hautelook\AliceBundle\PhpUnit\ReloadDatabaseTrait;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class TransactionControllerTest extends ApiTestCase
{
    use ReloadDatabaseTrait;

    public function testSomething(): void
    {
        $response = static::createClient()->request('GET', '/api/transactions');

        $this->assertResponseStatusCodeSame(401);

        $jwtManager = self::getContainer()->get(JWTTokenManagerInterface::class);
        $token = $jwtManager->create(['username' => 'testUser']);
    }
}
