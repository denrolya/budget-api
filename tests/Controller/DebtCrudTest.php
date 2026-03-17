<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Debt;
use App\Tests\BaseApiTestCase;

/**
 * API contract tests for Debt CRUD endpoints.
 *
 * Endpoints covered:
 *   GET    /api/v2/debts             (custom controller — DebtController::list)
 *   GET    /api/debts               (API Platform GetCollection)
 *   POST   /api/debts               (API Platform Post)
 *   PUT    /api/debts/{id}          (API Platform Put)
 *   DELETE /api/debts/{id}          (API Platform Delete — soft delete via Gedmo)
 *
 * Fixtures: DebtFixtures (1 debt: debtor='Test Debtor', balance=200, currency=EUR)
 */
class DebtCrudTest extends BaseApiTestCase
{
    private const DEBT_V2_LIST_URL = '/api/v2/debts';
    private const DEBT_API_URL = '/api/debts';

    // ──────────────────────────────────────────────────────────────────────
    //  LIST (v2) — response shape
    // ──────────────────────────────────────────────────────────────────────

    public function testListDebtsReturnsCorrectShape(): void
    {
        $response = $this->client->request('GET', self::DEBT_V2_LIST_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        self::assertIsArray($items);
        self::assertNotEmpty($items, 'Fixture debt must be present.');

        $debt = $items[0];

        // Keys expected by frontend DebtDTO
        self::assertArrayHasKey('id', $debt);
        self::assertArrayHasKey('debtor', $debt);
        self::assertArrayHasKey('currency', $debt);
        self::assertArrayHasKey('balance', $debt);
        self::assertArrayHasKey('createdAt', $debt);
        self::assertArrayHasKey('convertedValues', $debt);
    }

    public function testListDebtsFixtureValues(): void
    {
        $response = $this->client->request('GET', self::DEBT_V2_LIST_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        $fixtureDebt = null;
        foreach ($items as $item) {
            if ('Test Debtor' === $item['debtor']) {
                $fixtureDebt = $item;
                break;
            }
        }
        self::assertNotNull($fixtureDebt, 'Fixture debt must exist.');
        self::assertEquals('EUR', $fixtureDebt['currency']);
        self::assertEquals(200.0, (float) $fixtureDebt['balance']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  LIST — withClosed parameter
    // ──────────────────────────────────────────────────────────────────────

    public function testListDebtsWithClosedFalseExcludesClosedDebts(): void
    {
        // Create and close a debt
        $createResponse = $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'Closed Debtor',
                'currency' => 'EUR',
                'balance' => '50',
                'note' => 'Will be closed',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $debtId = $createResponse->toArray()['id'];

        // Close by DELETE (soft delete via Gedmo)
        $this->client->request('DELETE', self::DEBT_API_URL . '/' . $debtId);
        self::assertResponseStatusCodeSame(204);

        // List without closed — should NOT include the closed debt
        $response = $this->client->request('GET', self::DEBT_V2_LIST_URL);
        self::assertResponseIsSuccessful();
        $items = $response->toArray();

        $closedDebtIds = array_filter($items, static fn (array $item) => 'Closed Debtor' === $item['debtor']);
        self::assertEmpty($closedDebtIds, 'Closed debt must not appear without withClosed=true.');
    }

    public function testListDebtsWithClosedTrueIncludesClosedDebts(): void
    {
        // Create and close a debt
        $createResponse = $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'Closed Debtor For Include',
                'currency' => 'EUR',
                'balance' => '30',
                'note' => 'Will be closed and included',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $debtId = $createResponse->toArray()['id'];

        $this->client->request('DELETE', self::DEBT_API_URL . '/' . $debtId);
        self::assertResponseStatusCodeSame(204);

        // List WITH closed
        $response = $this->client->request('GET', $this->buildURL(self::DEBT_V2_LIST_URL, [
            'withClosed' => 'true',
        ]));
        self::assertResponseIsSuccessful();
        $items = $response->toArray();

        $closedDebts = array_filter($items, static fn (array $item) => 'Closed Debtor For Include' === $item['debtor']);
        self::assertNotEmpty($closedDebts, 'Closed debt must appear with withClosed=true.');

        // Closed debt must have closedAt set
        $closedDebt = array_values($closedDebts)[0];
        self::assertArrayHasKey('closedAt', $closedDebt);
        self::assertNotNull($closedDebt['closedAt'], 'Closed debt must have closedAt value.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateDebtReturnsCreatedDebt(): void
    {
        $response = $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'John Doe',
                'currency' => 'USD',
                'balance' => '100',
                'note' => 'Lent for dinner',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('id', $content);
        self::assertEquals('John Doe', $content['debtor']);
        self::assertEquals('USD', $content['currency']);
        self::assertEquals(100.0, (float) $content['balance']);

        // Verify in DB
        $debt = $this->entityManager()->getRepository(Debt::class)->find($content['id']);
        self::assertNotNull($debt);
        self::assertEquals('John Doe', $debt->getDebtor());
    }

    public function testCreateDebtWithZeroBalance(): void
    {
        $response = $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'Zero Balance Person',
                'currency' => 'EUR',
                'balance' => '0',
                'note' => '',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals(0.0, (float) $content['balance']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE
    // ──────────────────────────────────────────────────────────────────────

    public function testUpdateDebtChangesDebtorAndNote(): void
    {
        $debt = $this->entityManager()->getRepository(Debt::class)->findOneBy(['debtor' => 'Test Debtor']);
        self::assertNotNull($debt);

        $this->client->request('PUT', self::DEBT_API_URL . '/' . $debt->getId(), [
            'json' => [
                'debtor' => 'Updated Debtor',
                'currency' => 'EUR',
                'balance' => '200',
                'note' => 'Updated note',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager()->refresh($debt);
        self::assertEquals('Updated Debtor', $debt->getDebtor());
        self::assertEquals('Updated note', $debt->getNote());
    }

    // ──────────────────────────────────────────────────────────────────────
    //  DELETE (soft delete)
    // ──────────────────────────────────────────────────────────────────────

    public function testDeleteDebtSoftDeletesSetsClosedAt(): void
    {
        $createResponse = $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'SoftDelete Test',
                'currency' => 'EUR',
                'balance' => '10',
                'note' => '',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $debtId = $createResponse->toArray()['id'];

        $this->client->request('DELETE', self::DEBT_API_URL . '/' . $debtId);
        self::assertResponseStatusCodeSame(204);

        // Debt still exists in DB but is soft deleted (closedAt set).
        // Use DQL directly to bypass Gedmo soft-deletable filter.
        $this->entityManager()->getFilters()->disable('softdeleteable');
        $debt = $this->entityManager()->getRepository(Debt::class)->find($debtId);
        self::assertNotNull($debt, 'Soft-deleted debt must still exist in DB.');
        self::assertNotNull($debt->getClosedAt(), 'closedAt must be set after soft delete.');
    }

    // ──────────────────────────────────────────────────────────────────────
    //  VALIDATION
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateDebtMissingDebtorReturns422(): void
    {
        $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'currency' => 'EUR',
                'balance' => '100',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateDebtMissingCurrencyReturns422(): void
    {
        $this->client->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'No Currency',
                'balance' => '100',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    public function testListDebtsWithoutAuthReturns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('GET', self::DEBT_V2_LIST_URL);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateDebtWithoutAuthReturns401(): void
    {
        $unauthClient = static::createClient();
        $unauthClient->request('POST', self::DEBT_API_URL, [
            'json' => [
                'debtor' => 'Unauthorized',
                'currency' => 'EUR',
                'balance' => '100',
            ],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASES
    // ──────────────────────────────────────────────────────────────────────

    public function testListDebtsConvertedValuesPresent(): void
    {
        $response = $this->client->request('GET', self::DEBT_V2_LIST_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        $fixtureDebt = null;
        foreach ($items as $item) {
            if ('Test Debtor' === $item['debtor']) {
                $fixtureDebt = $item;
                break;
            }
        }
        self::assertNotNull($fixtureDebt);
        self::assertArrayHasKey('convertedValues', $fixtureDebt);
        self::assertIsArray($fixtureDebt['convertedValues']);
    }

    public function testApiPlatformDebtListAlsoWorks(): void
    {
        // Both /api/v2/debts and /api/debts should work
        $response = $this->client->request('GET', self::DEBT_API_URL);
        self::assertResponseIsSuccessful();

        $items = $response->toArray();
        self::assertIsArray($items);
        self::assertNotEmpty($items);
    }
}
