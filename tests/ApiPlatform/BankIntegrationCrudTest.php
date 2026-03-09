<?php

namespace App\Tests\ApiPlatform;

use App\Bank\BankProvider;
use App\Bank\SyncMethod;
use App\Entity\BankIntegration;
use App\Tests\BaseApiTestCase;

/**
 * Tests for the standard API Platform BankIntegration CRUD operations:
 *   - POST   /api/bank-integrations
 *   - GET    /api/bank-integrations
 *   - GET    /api/bank-integrations/{id}
 *   - DELETE /api/bank-integrations/{id}
 *
 * @group bank
 */
class BankIntegrationCrudTest extends BaseApiTestCase
{
    private const BASE = '/api/bank-integrations';

    // -------------------------------------------------------------------------
    // POST — create
    // -------------------------------------------------------------------------

    public function testCreateRequiresAuth(): void
    {
        $this->client->request('POST', self::BASE, [
            'headers' => ['authorization' => null],
            'json'    => ['provider' => 'monobank', 'syncMethod' => 'webhook'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateIntegrationSetsOwnerFromJwt(): void
    {
        $response = $this->client->request('POST', self::BASE, [
            'json' => ['provider' => 'monobank', 'syncMethod' => 'webhook'],
        ]);

        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        self::assertSame('monobank', $content['provider']);

        // Verify persistence and owner from DB.
        $this->em->clear();
        $integration = $this->em->getRepository(BankIntegration::class)->find($content['id']);
        self::assertNotNull($integration);
        self::assertTrue($integration->isActive());
        self::assertSame($this->testUser->getId(), $integration->getOwner()->getId());
    }

    public function testCreateWiseIntegration(): void
    {
        $response = $this->client->request('POST', self::BASE, [
            'json' => ['provider' => 'wise', 'syncMethod' => 'polling'],
        ]);

        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertSame('wise', $content['provider']);
        self::assertSame('polling', $content['syncMethod']);
    }

    // -------------------------------------------------------------------------
    // GET collection
    // -------------------------------------------------------------------------

    public function testListRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE, [
            'headers' => ['authorization' => null],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testListReturnsOnlyOwnIntegrations(): void
    {
        // Create two integrations for testUser.
        $this->client->request('POST', self::BASE, ['json' => ['provider' => 'monobank']]);
        $this->client->request('POST', self::BASE, ['json' => ['provider' => 'wise']]);

        $response = $this->client->request('GET', self::BASE);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertIsArray($content);

        // All returned items must belong to the authenticated user.
        foreach ($content as $item) {
            self::assertArrayHasKey('id', $item);
            $integration = $this->em->getRepository(BankIntegration::class)->find($item['id']);
            self::assertSame($this->testUser->getId(), $integration->getOwner()->getId());
        }
    }

    // -------------------------------------------------------------------------
    // GET item
    // -------------------------------------------------------------------------

    public function testGetIntegrationById(): void
    {
        $created = $this->client->request('POST', self::BASE, [
            'json' => ['provider' => 'monobank'],
        ])->toArray();

        $response = $this->client->request('GET', self::BASE . '/' . $created['id']);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertSame($created['id'], $content['id']);
        self::assertSame('monobank', $content['provider']);
        self::assertArrayHasKey('createdAt', $content);
    }

    public function testGetIntegrationOwnedByOtherUserReturns403(): void
    {
        // Create an integration directly in the DB for a different (anonymous) user.
        $user2 = new \App\Entity\User();
        $user2->setUsername('crud_other_user')->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->em->persist($user2);

        $integration = new BankIntegration();
        $integration
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setOwner($user2)
            ->setIsActive(true);
        $this->em->persist($integration);
        $this->em->flush();

        $this->client->request('GET', self::BASE . '/' . $integration->getId());
        self::assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    public function testDeleteRequiresAuth(): void
    {
        $created = $this->client->request('POST', self::BASE, [
            'json' => ['provider' => 'monobank'],
        ])->toArray();

        $this->client->request('DELETE', self::BASE . '/' . $created['id'], [
            'headers' => ['authorization' => null],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteOwnIntegration(): void
    {
        $created = $this->client->request('POST', self::BASE, [
            'json' => ['provider' => 'monobank'],
        ])->toArray();

        $id = $created['id'];
        $this->client->request('DELETE', self::BASE . '/' . $id);
        self::assertResponseStatusCodeSame(204);

        // Must be gone from DB.
        $this->em->clear();
        self::assertNull($this->em->getRepository(BankIntegration::class)->find($id));
    }

    public function testDeleteIntegrationOwnedByOtherUserReturns403(): void
    {
        $user2 = new \App\Entity\User();
        $user2->setUsername('crud_delete_other')->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->em->persist($user2);

        $integration = new BankIntegration();
        $integration
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setOwner($user2)
            ->setIsActive(true);
        $this->em->persist($integration);
        $this->em->flush();

        $this->client->request('DELETE', self::BASE . '/' . $integration->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
