<?php

declare(strict_types=1);

namespace App\Tests\ApiPlatform\Action;

use App\Bank\BankProvider;
use App\Bank\BankWebhookRegistrationService;
use App\Bank\SyncMethod;
use App\Entity\BankIntegration;
use App\Entity\User;
use App\Tests\BaseApiTestCase;

/**
 * API contract tests for BankIntegration webhook registration endpoint.
 *
 * Endpoints covered:
 *   POST /api/bank-integrations/{id}/register-webhook  — register a webhook for a bank integration
 *
 * @group bank
 */
class BankIntegrationRegisterWebhookActionTest extends BaseApiTestCase
{
    private int $monobankIntegrationId;
    private int $wiseIntegrationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monobankIntegrationId = $this->createIntegration(BankProvider::Monobank, SyncMethod::Webhook, $this->testUser);
        $this->wiseIntegrationId    = $this->createIntegration(BankProvider::Wise, SyncMethod::Polling, $this->testUser);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createIntegration(BankProvider $provider, SyncMethod $syncMethod, User $owner): int
    {
        $integration = new BankIntegration();
        $integration
            ->setProvider($provider)
            ->setSyncMethod($syncMethod)
            ->setOwner($owner)
            ->setIsActive(true);

        $this->em->persist($integration);
        $this->em->flush();

        return (int) $integration->getId();
    }

    private function url(int $id): string
    {
        return "/api/bank-integrations/{$id}/register-webhook";
    }

    /** @return \PHPUnit\Framework\MockObject\MockObject&BankWebhookRegistrationService */
    private function mockRegistrationService(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock(BankWebhookRegistrationService::class);
        $this->client->getContainer()->set(BankWebhookRegistrationService::class, $mock);

        return $mock;
    }

    // -------------------------------------------------------------------------
    // Authentication / authorisation
    // -------------------------------------------------------------------------

    /**
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testUnauthenticatedGets401(): void
    {
        $this->client->request('POST', $this->url($this->monobankIntegrationId), [
            'headers' => ['authorization' => null],
            'json'    => [],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testUnknownIntegrationReturns404(): void
    {
        $this->client->request('POST', $this->url(99999), ['json' => []]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testWrongOwnerReturns403(): void
    {
        // Create a second user and an integration belonging to them.
        $user2 = new User();
        $user2->setUsername('webhook_other_user')->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->em->persist($user2);

        $otherId = $this->createIntegration(BankProvider::Monobank, SyncMethod::Webhook, $user2);

        // Try to access user2's integration while authenticated as testUser.
        $this->client->request('POST', $this->url($otherId), ['json' => []]);

        self::assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------------------
    // Localhost detection
    // -------------------------------------------------------------------------

    /**
     * The default test client connects to localhost. Without WEBHOOK_BASE_URL the
     * action must refuse to register a webhook because the bank cannot reach a
     * private address.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testLocalhostRequestIsBlockedWith422(): void
    {
        $response = $this->client->request('POST', $this->url($this->monobankIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(422);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
        self::assertStringContainsStringIgnoringCase('localhost', $content['error']);
    }

    // -------------------------------------------------------------------------
    // Provider capability
    // -------------------------------------------------------------------------

    /**
     * Wise supports webhooks too. On localhost, registration is still blocked
     * because the callback URL is not publicly reachable.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testWiseWebhookRegistrationIsBlockedOnLocalhostWith422(): void
    {
        $response = $this->client->request('POST', $this->url($this->wiseIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(422);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
        self::assertStringContainsStringIgnoringCase('localhost', $content['error']);
    }

    // -------------------------------------------------------------------------
    // WEBHOOK_BASE_URL override path (success and failure)
    // -------------------------------------------------------------------------

    /**
     * When WEBHOOK_BASE_URL is set the action bypasses the localhost check, calls
     * registerWebhook on the provider, and returns the resolved webhook URL.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testRegisterWebhookSuccessReturnWebhookUrl(): void
    {
        $mock = $this->mockRegistrationService();
        $mock
            ->expects(self::once())
            ->method('register')
            ->willReturn('https://prod.example.com/api/webhooks/monobank');

        $response = $this->client->request('POST', $this->url($this->monobankIntegrationId), ['json' => []]);

        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('webhookUrl', $content);
        self::assertStringStartsWith('https://prod.example.com', $content['webhookUrl']);
        self::assertStringContainsString('monobank', $content['webhookUrl']);
    }

    /**
     * When the bank API call fails the action must surface 502 Bad Gateway.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::__invoke
     */
    public function testBankApiFailureReturns502(): void
    {
        $mock = $this->mockRegistrationService();
        $mock
            ->method('register')
            ->willThrowException(new \RuntimeException('Monobank: connection refused'));

        $response = $this->client->request('POST', $this->url($this->monobankIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(502);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
    }
}
