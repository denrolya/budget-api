<?php

declare(strict_types=1);

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

    /**
     * @covers \App\Entity\BankIntegration
     */
    public function testCreateRequiresAuth(): void
    {
        $this->client->request('POST', self::BASE, [
            'headers' => ['authorization' => null],
            'json' => ['provider' => 'monobank', 'syncMethod' => 'webhook'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Entity\BankIntegration
     */
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
        $this->entityManager()->clear();
        $integration = $this->entityManager()->getRepository(BankIntegration::class)->find($content['id']);
        \assert($integration instanceof BankIntegration);
        self::assertTrue($integration->isActive());
        $owner = $integration->getOwner();
        \assert(null !== $owner);
        self::assertSame($this->testUser->getId(), $owner->getId());
    }

    /**
     * @covers \App\Entity\BankIntegration
     */
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

    /**
     * @covers \App\Entity\BankIntegration
     */
    public function testListRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE, [
            'headers' => ['authorization' => null],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Entity\BankIntegration
     */
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
            $integration = $this->entityManager()->getRepository(BankIntegration::class)->find($item['id']);
            \assert($integration instanceof BankIntegration);
            $owner = $integration->getOwner();
            \assert(null !== $owner);
            self::assertSame($this->testUser->getId(), $owner->getId());
        }
    }

    // -------------------------------------------------------------------------
    // GET item
    // -------------------------------------------------------------------------

    /**
     * @covers \App\Entity\BankIntegration
     */
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
        // Security: credentials must NOT be exposed in read responses
        self::assertArrayNotHasKey('credentials', $content, 'Credentials must not be serialized in GET responses.');
    }

    /**
     * @covers \App\Entity\BankIntegration
     */
    public function testGetIntegrationOwnedByOtherUserReturns403(): void
    {
        // Create an integration directly in the DB for a different (anonymous) user.
        $user2 = new \App\Entity\User();
        $user2->setUsername('crud_other_user')->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->entityManager()->persist($user2);

        $integration = new BankIntegration();
        $integration
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setOwner($user2)
            ->setIsActive(true);
        $this->entityManager()->persist($integration);
        $this->entityManager()->flush();

        $this->client->request('GET', self::BASE . '/' . $integration->getId());
        self::assertResponseStatusCodeSame(403);
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    /**
     * @covers \App\Entity\BankIntegration
     */
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

    /**
     * @covers \App\Entity\BankIntegration
     */
    public function testDeleteOwnIntegration(): void
    {
        $created = $this->client->request('POST', self::BASE, [
            'json' => ['provider' => 'monobank'],
        ])->toArray();

        $id = $created['id'];
        $this->client->request('DELETE', self::BASE . '/' . $id);
        self::assertResponseStatusCodeSame(204);

        // Must be gone from DB.
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(BankIntegration::class)->find($id));
    }

    /**
     * @covers \App\Entity\BankIntegration
     */
    public function testDeleteIntegrationOwnedByOtherUserReturns403(): void
    {
        $user2 = new \App\Entity\User();
        $user2->setUsername('crud_delete_other')->setPassword('pw')->setRoles(['ROLE_USER']);
        $this->entityManager()->persist($user2);

        $integration = new BankIntegration();
        $integration
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setOwner($user2)
            ->setIsActive(true);
        $this->entityManager()->persist($integration);
        $this->entityManager()->flush();

        $this->client->request('DELETE', self::BASE . '/' . $integration->getId());
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @covers \App\Entity\BankIntegration
     */
    public function testUpdateIntegration_ownedByOtherUser_returns403(): void
    {
        $otherUser = $this->createOtherUser('bank_put');

        $integration = new BankIntegration();
        $integration
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setOwner($otherUser)
            ->setIsActive(true);
        $this->entityManager()->persist($integration);
        $this->entityManager()->flush();

        $this->client->request('PUT', self::BASE . '/' . $integration->getId(), [
            'json' => ['provider' => 'monobank', 'syncMethod' => 'webhook'],
        ]);
        // security: 'object.getOwner() == user' on the Put operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }
}
