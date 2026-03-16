<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;

/**
 * API contract tests for authentication endpoints.
 *
 * Endpoints covered:
 *   POST /api/login_check           — JWT login
 *   GET  /api/v2/auth/token/refresh — refresh token
 *   GET  /api/users/{username}      — get user profile
 *   PUT  /api/users/{username}      — update user profile (baseCurrency, dashboardStatistics)
 *
 * Fixtures: UserAndAccountFixtures (username='test_user', password='password123')
 */
class AuthenticationTest extends BaseApiTestCase
{
    private const LOGIN_URL = '/api/login_check';
    private const REFRESH_URL = '/api/v2/auth/token/refresh';
    private const TEST_PASSWORD = 'password123';

    // ──────────────────────────────────────────────────────────────────────
    //  LOGIN
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Entity\User
     */
    public function testLogin_validCredentials_returnsToken(): void
    {
        $unauthClient = static::createClient();

        $response = $unauthClient->request('POST', self::LOGIN_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'username' => self::TEST_USERNAME,
                'password' => self::TEST_PASSWORD,
            ],
        ]);

        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('token', $content);
        self::assertNotEmpty($content['token']);
    }

    /**
     * @covers \App\Entity\User
     */
    public function testLogin_invalidPassword_returns401(): void
    {
        $unauthClient = static::createClient();

        $unauthClient->request('POST', self::LOGIN_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'username' => self::TEST_USERNAME,
                'password' => 'wrong_password',
            ],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Entity\User
     */
    public function testLogin_nonExistentUser_returns401(): void
    {
        $unauthClient = static::createClient();

        $unauthClient->request('POST', self::LOGIN_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'username' => 'nonexistentuser',
                'password' => self::TEST_PASSWORD,
            ],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Entity\User
     */
    public function testLogin_emptyBody_returns400(): void
    {
        $unauthClient = static::createClient();

        $unauthClient->request('POST', self::LOGIN_URL, [
            'body' => '{}',
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        // Symfony's JsonLoginAuthenticator returns 400 for missing/empty credentials
        self::assertResponseStatusCodeSame(400);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  LOGIN → use token
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Entity\User
     */
    public function testLogin_tokenGrantsAccess(): void
    {
        $unauthClient = static::createClient();

        $response = $unauthClient->request('POST', self::LOGIN_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'username' => self::TEST_USERNAME,
                'password' => self::TEST_PASSWORD,
            ],
        ]);
        $token = $response->toArray()['token'];

        // Use the token to access a protected endpoint
        $unauthClient->request('GET', '/api/v2/transaction', [
            'auth_bearer' => $token,
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @covers \App\Entity\User
     */
    public function testProtectedEndpoint_withoutToken_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', '/api/v2/transaction');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Entity\User
     */
    public function testProtectedEndpoint_withInvalidToken_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', '/api/v2/transaction', [
            'auth_bearer' => 'invalid.jwt.token',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  TOKEN REFRESH
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\AuthController::refreshToken
     */
    public function testRefreshToken_returnsNewToken(): void
    {
        $response = $this->client->request('GET', self::REFRESH_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('token', $content);
        self::assertNotEmpty($content['token']);
    }

    /**
     * @covers \App\Controller\AuthController::refreshToken
     */
    public function testRefreshToken_withoutAuth_returns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::REFRESH_URL);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  USER PROFILE — GET
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Entity\User
     */
    public function testGetUser_returnsProfileShape(): void
    {
        $response = $this->client->request('GET', '/api/users/' . self::TEST_USERNAME);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('username', $content);
        self::assertArrayHasKey('baseCurrency', $content);
        self::assertEquals(self::TEST_USERNAME, $content['username']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  USER PROFILE — PUT (update baseCurrency)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Entity\User
     */
    public function testUpdateUser_baseCurrency(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => self::TEST_USERNAME]);
        self::assertNotNull($user);
        $originalCurrency = $user->getBaseCurrency();

        $newCurrency = $originalCurrency === 'EUR' ? 'USD' : 'EUR';

        $this->client->request('PUT', '/api/users/' . self::TEST_USERNAME, [
            'json' => [
                'baseCurrency' => $newCurrency,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->refresh($user);
        self::assertEquals($newCurrency, $user->getBaseCurrency());
    }
}
