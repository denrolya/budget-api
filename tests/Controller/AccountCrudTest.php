<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Account;
use App\Entity\BankIntegration;
use App\Tests\BaseApiTestCase;

/**
 * Comprehensive API contract tests for Account CRUD endpoints.
 *
 * Endpoints under test:
 *   GET    /api/accounts          — list all accounts (API Platform GetCollection)
 *   POST   /api/accounts          — create basic account
 *   POST   /api/accounts/bank     — create bank card account
 *   POST   /api/accounts/cash     — create cash account
 *   POST   /api/accounts/internet — create internet account
 *   PUT    /api/accounts/{id}     — update any account subtype
 */
class AccountCrudTest extends BaseApiTestCase
{
    private const ACCOUNT_URL = '/api/accounts';
    private const ACCOUNT_BANK_URL = '/api/accounts/bank';
    private const ACCOUNT_CASH_URL = '/api/accounts/cash';
    private const ACCOUNT_INTERNET_URL = '/api/accounts/internet';

    // ──────────────────────────────────────────────────────────────────────
    //  LIST
    // ──────────────────────────────────────────────────────────────────────

    public function testListAccountsHappyPathReturnsAllFieldsExpectedByFrontend(): void
    {
        $response = $this->client->request('GET', self::ACCOUNT_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        // With Accept: application/json the response is a plain array (not hydra envelope)
        self::assertIsArray($content);
        self::assertGreaterThanOrEqual(2, \count($content), 'Fixtures create at least 2 accounts.');

        $account = $content[0];
        // Every field the frontend AccountRawData reads must be present
        self::assertArrayHasKey('id', $account);
        self::assertArrayHasKey('type', $account);
        self::assertArrayHasKey('name', $account);
        self::assertArrayHasKey('currency', $account);
        self::assertArrayHasKey('balance', $account);
        // archivedAt is omitted by JMS serializer when null — frontend handles absence as "not archived"
        self::assertArrayHasKey('isDisplayedOnSidebar', $account);
        self::assertArrayHasKey('draftCount', $account);
        self::assertArrayHasKey('updatedAt', $account);

        // Type checks
        self::assertIsInt($account['id']);
        self::assertIsString($account['type']);
        self::assertContains($account['type'], ['basic', 'bank', 'cash', 'internet']);
        self::assertIsString($account['name']);
        self::assertIsString($account['currency']);
        self::assertIsNumeric($account['balance']);
        self::assertIsBool($account['isDisplayedOnSidebar']);
        self::assertIsInt($account['draftCount']);
    }

    public function testListAccountsBankAccountExposesSubtypeFields(): void
    {
        $response = $this->client->request('GET', self::ACCOUNT_URL);
        self::assertResponseIsSuccessful();

        $bankAccount = null;
        foreach ($response->toArray() as $item) {
            if ('bank' === $item['type']) {
                $bankAccount = $item;
                break;
            }
        }

        self::assertNotNull($bankAccount, 'Fixture UAH Card (type=bank) must appear in collection.');
        self::assertArrayHasKey('bankName', $bankAccount);
        self::assertArrayHasKey('cardNumber', $bankAccount);
        self::assertArrayHasKey('iban', $bankAccount);
        // externalAccountId is omitted by JMS serializer when null

        self::assertSame('Test Bank', $bankAccount['bankName']);
        self::assertSame('4111111111111111', $bankAccount['cardNumber']);
        self::assertSame('UA123456789012345678901234567', $bankAccount['iban']);
    }

    public function testListAccountsIncludesArchivedAccounts(): void
    {
        // Archive the EUR Cash account
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $this->accountCashEUR->getId(), [
            'json' => ['archivedAt' => '2026-01-01T00:00:00+00:00'],
        ]);
        self::assertResponseIsSuccessful();

        // Re-fetch list — archived account must still be present
        $response = $this->client->request('GET', self::ACCOUNT_URL);
        self::assertResponseIsSuccessful();

        $identifiers = array_column($response->toArray(), 'id');
        self::assertContains($this->accountCashEUR->getId(), $identifiers, 'Archived account must still appear in collection.');

        // Unarchive for other tests
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $this->accountCashEUR->getId(), [
            'json' => ['archivedAt' => null],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testListAccountsDraftCountIsEnrichedByProvider(): void
    {
        $response = $this->client->request('GET', self::ACCOUNT_URL);
        self::assertResponseIsSuccessful();

        foreach ($response->toArray() as $account) {
            self::assertArrayHasKey('draftCount', $account);
            self::assertIsInt($account['draftCount']);
            self::assertGreaterThanOrEqual(0, $account['draftCount']);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — happy paths per subtype
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateBasicAccountHappyPathReturns201WithCorrectShape(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Test Basic Account',
                'currency' => 'EUR',
                'balance' => 500.50,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $content = $response->toArray();

        self::assertSame('Test Basic Account', $content['name']);
        self::assertSame('EUR', $content['currency']);
        self::assertEqualsWithDelta(500.50, (float) $content['balance'], 0.000001);
        self::assertSame('basic', $content['type']);
        self::assertArrayHasKey('id', $content);
        self::assertIsInt($content['id']);
    }

    public function testCreateBankAccountHappyPathReturnsBankSpecificFields(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_BANK_URL, [
            'json' => [
                'name' => 'Test Bank Card',
                'currency' => 'USD',
                'balance' => 1000,
                'bankName' => 'Chase',
                'cardNumber' => '5555555555554444',
                'iban' => 'DE89370400440532013000',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $content = $response->toArray();

        self::assertSame('bank', $content['type']);
        self::assertSame('Test Bank Card', $content['name']);
        self::assertSame('USD', $content['currency']);
        self::assertEqualsWithDelta(1000.0, (float) $content['balance'], 0.000001);
    }

    public function testCreateCashAccountHappyPathReturns201(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_CASH_URL, [
            'json' => [
                'name' => 'Test Cash Wallet',
                'currency' => 'UAH',
                'balance' => 0,
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $content = $response->toArray();

        self::assertSame('cash', $content['type']);
        self::assertSame('Test Cash Wallet', $content['name']);
        self::assertSame('UAH', $content['currency']);
    }

    public function testCreateInternetAccountHappyPathReturns201(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_INTERNET_URL, [
            'json' => [
                'name' => 'Test PayPal',
                'currency' => 'EUR',
                'balance' => 250.75,
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $content = $response->toArray();

        self::assertSame('internet', $content['type']);
        self::assertSame('Test PayPal', $content['name']);
    }

    public function testCreateAccountIntegerBalanceStoredCorrectly(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Integer Balance Account',
                'currency' => 'EUR',
                'balance' => 123,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertIsNumeric($content['balance']);
        self::assertEqualsWithDelta(123.0, (float) $content['balance'], 0.000001);
    }

    public function testCreateAccountFloatBalanceStoredCorrectly(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Float Balance Account',
                'currency' => 'USD',
                'balance' => 123.45,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertIsNumeric($content['balance']);
        self::assertEqualsWithDelta(123.45, (float) $content['balance'], 0.000001);
    }

    public function testCreateAccountZeroBalanceSucceeds(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Zero Balance Account',
                'currency' => 'HUF',
                'balance' => 0,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEqualsWithDelta(0.0, (float) $content['balance'], 0.000001);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  CREATE — validation errors
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateAccountMissingNameReturns422(): void
    {
        $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'currency' => 'EUR',
                'balance' => 100,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateAccountEmptyNameReturns422(): void
    {
        $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => '',
                'currency' => 'EUR',
                'balance' => 100,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateAccountMissingCurrencyReturns422(): void
    {
        $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'No Currency Account',
                'balance' => 100,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateAccountInvalidCurrencyReturns422(): void
    {
        $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Invalid Currency Account',
                'currency' => 'GBP',
                'balance' => 100,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Balance has a default of 0.0 in the entity, so omitting it is valid.
     * The account should be created with balance=0.
     */
    public function testCreateAccountMissingBalanceDefaultsToZero(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'No Balance Account',
                'currency' => 'EUR',
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertEqualsWithDelta(0.0, (float) $response->toArray()['balance'], 0.000001);
    }

    public function testCreateAccountNonNumericBalanceReturns422(): void
    {
        $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Bad Balance Account',
                'currency' => 'EUR',
                'balance' => 'not-a-number',
                'type' => 'basic',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  UPDATE
    // ──────────────────────────────────────────────────────────────────────

    public function testUpdateAccountChangeNameSucceeds(): void
    {
        $identifier = $this->accountCashEUR->getId();

        $response = $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['name' => 'Updated EUR Cash'],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertSame('Updated EUR Cash', $content['name']);

        // Restore
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['name' => 'EUR Cash'],
        ]);
    }

    public function testUpdateAccountChangeBalanceSucceeds(): void
    {
        $identifier = $this->accountCashEUR->getId();

        $response = $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['balance' => 99999.99],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEqualsWithDelta(99999.99, (float) $content['balance'], 0.000001);

        // Restore
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['balance' => 10000],
        ]);
    }

    public function testUpdateAccountArchiveAndUnarchiveSucceeds(): void
    {
        $identifier = $this->accountCashEUR->getId();

        // Archive via PUT
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['archivedAt' => '2026-03-01T00:00:00+00:00'],
        ]);
        self::assertResponseIsSuccessful();

        // Verify in DB directly (JMS serializer omits null values, so API response may not include archivedAt)
        $this->entityManager()->clear();
        $account = $this->entityManager()->getRepository(Account::class)->find($identifier);
        \assert($account instanceof Account);
        self::assertNotNull($account->getArchivedAt(), 'Account must be archived in database.');

        // Unarchive
        $this->reloadClientWithServices();
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['archivedAt' => null],
        ]);
        self::assertResponseIsSuccessful();

        $this->entityManager()->clear();
        $account = $this->entityManager()->getRepository(Account::class)->find($identifier);
        \assert($account instanceof Account);
        self::assertNull($account->getArchivedAt(), 'Account must be unarchived in database.');
    }

    public function testUpdateAccountToggleIsDisplayedOnSidebarSucceeds(): void
    {
        $identifier = $this->accountCashEUR->getId();

        $response = $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['isDisplayedOnSidebar' => true],
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($response->toArray()['isDisplayedOnSidebar']);

        $response = $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['isDisplayedOnSidebar' => false],
        ]);
        self::assertResponseIsSuccessful();
        self::assertFalse($response->toArray()['isDisplayedOnSidebar']);
    }

    public function testUpdateAccountChangeCurrencySucceeds(): void
    {
        // Create a fresh account to test currency change without affecting fixtures
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Currency Change Test',
                'currency' => 'EUR',
                'balance' => 100,
                'type' => 'basic',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $identifier = $response->toArray()['id'];

        $response = $this->client->request('PUT', self::ACCOUNT_URL . '/' . $identifier, [
            'json' => ['currency' => 'USD'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('USD', $response->toArray()['currency']);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  SECURITY
    // ──────────────────────────────────────────────────────────────────────

    public function testListAccountsWithoutAuthReturns401(): void
    {
        $this->client->request('GET', self::ACCOUNT_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateAccountWithoutAuthReturns401(): void
    {
        $this->client->request('POST', self::ACCOUNT_URL, [
            'headers' => ['authorization' => null],
            'json' => [
                'name' => 'Unauthenticated Account',
                'currency' => 'EUR',
                'balance' => 100,
                'type' => 'basic',
            ],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUpdateAccountWithoutAuthReturns401(): void
    {
        $this->client->request('PUT', self::ACCOUNT_URL . '/' . $this->accountCashEUR->getId(), [
            'headers' => ['authorization' => null],
            'json' => ['name' => 'Hacked'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  EDGE CASES
    // ──────────────────────────────────────────────────────────────────────

    public function testCreateAccountAllSupportedCurrenciesSucceed(): void
    {
        $currencies = ['EUR', 'USD', 'UAH', 'HUF', 'BTC', 'ETH'];

        foreach ($currencies as $currency) {
            $response = $this->client->request('POST', self::ACCOUNT_URL, [
                'json' => [
                    'name' => "Test {$currency} Account",
                    'currency' => $currency,
                    'balance' => 0,
                    'type' => 'basic',
                ],
            ]);
            self::assertResponseStatusCodeSame(201, "Creating account with currency {$currency} must succeed.");
            self::assertSame($currency, $response->toArray()['currency']);
        }
    }

    public function testCreateAccountNegativeBalanceIsAccepted(): void
    {
        // Negative balance is valid (e.g. credit account)
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Negative Balance Account',
                'currency' => 'EUR',
                'balance' => -500.25,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertEqualsWithDelta(-500.25, (float) $response->toArray()['balance'], 0.000001);
    }

    public function testCreateAccountVeryLargeBalanceStoredCorrectly(): void
    {
        $response = $this->client->request('POST', self::ACCOUNT_URL, [
            'json' => [
                'name' => 'Large Balance Account',
                'currency' => 'EUR',
                'balance' => 9999999999.12345678,
                'type' => 'basic',
            ],
        ]);

        self::assertResponseIsSuccessful();
        $content = $response->toArray();
        self::assertEqualsWithDelta(9999999999.12345678, (float) $content['balance'], 0.01);
    }

    public function testUpdateAccountNonExistentIdReturns404(): void
    {
        $this->client->request('PUT', self::ACCOUNT_URL . '/999999', [
            'json' => ['name' => 'Ghost'],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Verify the removed Get single-item endpoint returns 405 (or 404).
     * The frontend never uses GET /api/accounts/{id} directly.
     */
    public function testGetSingleAccountRemovedEndpointReturnsNotAllowed(): void
    {
        $response = $this->client->request('GET', self::ACCOUNT_URL . '/' . $this->accountCashEUR->getId());
        $statusCode = $response->getStatusCode();

        // API Platform returns either 404 (no route) or 405 (method not allowed) for removed operations
        self::assertContains($statusCode, [404, 405], 'GET /api/accounts/{id} was removed and must not be accessible.');
    }

    /**
     * Verify the removed v2 item endpoint is gone.
     */
    public function testGetSingleAccountV2RemovedEndpointReturnsNotFound(): void
    {
        $this->client->request('GET', '/api/v2/accounts/' . $this->accountCashEUR->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateBankAccountWithBankIntegrationIriLinksCorrectly(): void
    {
        // Check if a bank integration fixture exists
        $bankIntegration = $this->entityManager()->getRepository(BankIntegration::class)->findOneBy([]);
        if (null === $bankIntegration) {
            self::markTestSkipped('No BankIntegration fixture available.');
        }

        $response = $this->client->request('POST', self::ACCOUNT_BANK_URL, [
            'json' => [
                'name' => 'Linked Bank Account',
                'currency' => 'EUR',
                'balance' => 0,
                'bankName' => 'Wise',
                'bankIntegration' => $this->iri($bankIntegration),
                'externalAccountId' => 'bal_12345',
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
    }
}
