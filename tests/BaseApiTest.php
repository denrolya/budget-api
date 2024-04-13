<?php

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Spatie\Snapshots\MatchesSnapshots;

class BaseApiTest extends ApiTestCase
{
    use WithMockFixerTrait, MatchesSnapshots;

    protected const TEST_USERNAME = 'drolya';

    protected Client $client;

    protected ?EntityManagerInterface $em;

    private ?string $authToken = null;

    protected function setUp(): void
    {
        $this->client = $this->createClientWithCredentials();
        $this->createFixerServiceMock();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em->close();
        $this->em = null;
    }

    protected function createClientWithCredentials($token = null): Client
    {
        $token = $token ?: $this->getToken();

        return static::createClient([], ['headers' => ['authorization' => 'Bearer '.$token]]);
    }

    protected function getToken($username = self::TEST_USERNAME): string
    {
        if ($this->authToken) {
            return $this->authToken;
        }

        $container = self::getContainer();
        $user = $container
            ->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        $this->authToken = $container->get(JWTTokenManagerInterface::class)->create($user);

        return $this->authToken;
    }

    protected function buildURL(string $path, array $queryParams): string
    {
        $url = $path;
        if (!empty($queryParams)) {
            $url .= '?'.http_build_query($queryParams);
        }

        return $url;
    }

}
