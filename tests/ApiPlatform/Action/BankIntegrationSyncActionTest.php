<?php

declare(strict_types=1);

namespace App\Tests\ApiPlatform\Action;

use App\Bank\BankProvider;
use App\Bank\BankSyncService;
use App\Bank\SyncMethod;
use App\Entity\BankIntegration;
use App\Entity\User;
use App\Tests\BaseApiTestCase;

/**
 * API contract tests for BankIntegration sync endpoint.
 *
 * Endpoints covered:
 *   POST /api/bank-integrations/{id}/sync  — trigger a manual sync for a bank integration
 *
 * @group bank
 */
class BankIntegrationSyncActionTest extends BaseApiTestCase
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
        return "/api/bank-integrations/{$id}/sync";
    }

    // -------------------------------------------------------------------------
    // Authentication / authorisation
    // -------------------------------------------------------------------------

    /**
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testUnauthenticatedGets401(): void
    {
        $this->client->request('POST', $this->url($this->wiseIntegrationId), [
            'headers' => ['authorization' => null],
            'json'    => [],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testUnknownIntegrationReturns404(): void
    {
        $this->client->request('POST', $this->url(99999), ['json' => []]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testWrongOwnerReturns403(): void
    {
        $user2 = new User();
        $user2->setUsername('sync_other_user')->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->em->persist($user2);

        $otherId = $this->createIntegration(BankProvider::Wise, SyncMethod::Polling, $user2);

        $this->client->request('POST', $this->url($otherId), ['json' => []]);

        self::assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------------------
    // Provider capability
    // -------------------------------------------------------------------------

    /**
     * Monobank is webhook-only and does not implement PollingCapableInterface.
     * BankSyncService::sync() throws LogicException which the action maps to 422.
     * No mocking required — this exercises the real service.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testMonobankSyncReturns422DueToNoPollingSupport(): void
    {
        $response = $this->client->request('POST', $this->url($this->monobankIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(422);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
        self::assertStringContainsStringIgnoringCase('polling', $content['error']);
    }

    // -------------------------------------------------------------------------
    // Sync success / error
    // -------------------------------------------------------------------------

    /**
     * The action delegates to BankSyncService and echoes back the created-draft count.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testSyncReturnsCreatedDraftCount(): void
    {
        $mock = $this->createMock(BankSyncService::class);
        $mock->method('sync')->willReturn(7);
        $this->client->getContainer()->set(BankSyncService::class, $mock);

        $response = $this->client->request('POST', $this->url($this->wiseIntegrationId), ['json' => []]);

        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertSame(7, $content['created']);
    }

    /**
     * A date range is accepted as query params; both are forwarded to BankSyncService.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testSyncForwardsDateRangeToService(): void
    {
        $mock = $this->createMock(BankSyncService::class);
        $mock
            ->expects(self::once())
            ->method('sync')
            ->with(
                self::isInstanceOf(BankIntegration::class),
                self::callback(fn ($d) => $d instanceof \DateTimeImmutable && $d->format('Y-m-d') === '2026-01-01'),
                self::callback(fn ($d) => $d instanceof \DateTimeImmutable && $d->format('Y-m-d') === '2026-01-31'),
            )
            ->willReturn(3);

        $this->client->getContainer()->set(BankSyncService::class, $mock);

        $response = $this->client->request(
            'POST',
            $this->url($this->wiseIntegrationId) . '?from=2026-01-01&to=2026-01-31',
            ['json' => []],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(3, $response->toArray()['created']);
    }

    /**
     * Unexpected errors from the bank API are surfaced as 502 Bad Gateway.
     *
     * @covers \App\ApiPlatform\Action\BankIntegrationSyncAction::__invoke
     */
    public function testBankApiErrorReturns502(): void
    {
        $mock = $this->createMock(BankSyncService::class);
        $mock->method('sync')->willThrowException(new \RuntimeException('Wise API timeout'));
        $this->client->getContainer()->set(BankSyncService::class, $mock);

        $response = $this->client->request('POST', $this->url($this->wiseIntegrationId), ['json' => []]);

        self::assertResponseStatusCodeSame(502);

        $content = $response->toArray(false);
        self::assertArrayHasKey('error', $content);
    }
}
