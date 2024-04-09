<?php

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

class BaseApiTest extends ApiTestCase
{
    protected const TEST_USERNAME = 'drolya';

    protected function generateJWTToken(?string $username = self::TEST_USERNAME): string
    {
        $container = self::getContainer();
        $user = $container
            ->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }

}
