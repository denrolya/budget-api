<?php

namespace App\Tests\ApiPlatform\Action;

use App\Bank\BankProvider;
use App\Bank\BankProviderRegistry;
use App\Bank\Provider\MonobankProvider;
use App\Bank\SyncMethod;
use App\Entity\BankIntegration;
use App\Entity\User;
use App\Tests\BaseApiTestCase;

/**
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

    /**
     * Replace BankIntegrationRegisterWebhookAction in the container with a version
     * that has a fixed WEBHOOK_BASE_URL so the localhost guard is bypassed and no
     * real HTTP calls are made.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject&MonobankProvider
     */
    private function mockMonobankProvider(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mockProvider = $this->createMock(MonobankProvider::class);

        $mockRegistry = $this->createMock(BankProviderRegistry::class);
        $mockRegistry->method('get')->willReturn($mockProvider);

        // Retrieve the already-initialised service so AbstractController's service
        // locator (needed for getUser()) is already wired by the DI container.
        $container = $this->client->getContainer();
        $action = $container->get(\App\ApiPlatform\Action\BankIntegrationRegisterWebhookAction::class);

        $ref = new \ReflectionClass($action);

        $registryProp = $ref->getProperty('registry');
        $registryProp->setAccessible(true);
        $registryProp->setValue($action, $mockRegistry);

        $webhookProp = $ref->getProperty('webhookBaseUrl');
        $webhookProp->setAccessible(true);
        $webhookProp->setValue($action, 'https://prod.example.com');

        return $mockProvider;
    }

    // -------------------------------------------------------------------------
    // Authentication / authorisation
    // -------------------------------------------------------------------------

    public function testUnauthenticatedGets401(): void
    {
        $this->client->request('POST', $this->url($this->monobankIntegrationId), [
            'headers' => ['authorization' => null],
            'json'    => [],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUnknownIntegrationReturns404(): void
    {
        $this->client->request('POST', $this->url(99999), ['json' => []]);

        self::assertResponseStatusCodeSame(404);
    }

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
     * Wise does not implement WebhookCapableInterface; the capability check fires
     * before the localhost check, so this returns 422 even from localhost.
     */
    public function testNonWebhookProviderIsRejectedWith422(): void
    {
        $response = $this->client->request('POST', $this->url($this->wiseIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(422);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
        self::assertStringContainsStringIgnoringCase('does not support webhooks', $content['error']);
    }

    // -------------------------------------------------------------------------
    // WEBHOOK_BASE_URL override path (success and failure)
    // -------------------------------------------------------------------------

    /**
     * When WEBHOOK_BASE_URL is set the action bypasses the localhost check, calls
     * registerWebhook on the provider, and returns the resolved webhook URL.
     */
    public function testRegisterWebhookSuccessReturnWebhookUrl(): void
    {
        $mockProvider = $this->mockMonobankProvider();
        $mockProvider->expects(self::once())->method('registerWebhook');

        $response = $this->client->request('POST', $this->url($this->monobankIntegrationId), ['json' => []]);

        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('webhookUrl', $content);
        self::assertStringStartsWith('https://prod.example.com', $content['webhookUrl']);
        self::assertStringContainsString('monobank', $content['webhookUrl']);
    }

    /**
     * When the bank API call fails the action must surface 502 Bad Gateway.
     */
    public function testBankApiFailureReturns502(): void
    {
        $mockProvider = $this->mockMonobankProvider();
        $mockProvider
            ->method('registerWebhook')
            ->willThrowException(new \RuntimeException('Monobank: connection refused'));

        $response = $this->client->request('POST', $this->url($this->monobankIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(502);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
    }
}
