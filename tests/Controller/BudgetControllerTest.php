<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\ExpenseCategory;
use App\Tests\BaseApiTestCase;

/**
 * @group budget
 */
class BudgetControllerTest extends BaseApiTestCase
{
    /** API Platform handles Budget CRUD at /api/budgets */
    private const BUDGET_API_URL = '/api/budgets';

    /** BudgetController still handles analytics */
    private const BUDGET_V2_URL = '/api/v2/budget';

    private function findBudget(string $name): Budget
    {
        $budget = $this->em->getRepository(Budget::class)->findOneBy(['name' => $name]);
        self::assertNotNull($budget, "Budget '$name' not found in fixtures");
        assert($budget instanceof Budget);

        return $budget;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // List (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @group smoke
     */
    public function testListRequiresAuth(): void
    {
        $this->client->request('GET', self::BUDGET_API_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @group smoke
     */
    public function testListReturnsBudgets(): void
    {
        $response = $this->client->request('GET', self::BUDGET_API_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertIsArray($content);

        // Four budgets loaded by BudgetFixtures
        self::assertCount(4, $content);

        $first = $content[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('name', $first);
        self::assertArrayHasKey('periodType', $first);
        self::assertArrayHasKey('startDate', $first);
        self::assertArrayHasKey('endDate', $first);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Get single (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetBudgetReturnsLinesAndMeta(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('GET', self::BUDGET_API_URL . '/' . $budget->getId());
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('January 2021', $content['name']);
        self::assertEquals('monthly', $content['periodType']);
        self::assertEquals('2021-01-01', substr($content['startDate'], 0, 10));
        self::assertEquals('2021-01-31', substr($content['endDate'], 0, 10));
        self::assertArrayHasKey('lines', $content);
        self::assertCount(5, $content['lines']);

        $line = $content['lines'][0];
        self::assertArrayHasKey('id', $line);
        self::assertArrayHasKey('categoryId', $line);
        self::assertArrayHasKey('plannedAmount', $line);
        self::assertArrayHasKey('plannedCurrency', $line);
    }

    public function testGetNonExistentBudgetReturns404(): void
    {
        $this->client->request('GET', self::BUDGET_API_URL . '/999999');
        self::assertResponseStatusCodeSame(404);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Create (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateMonthlyBudget(): void
    {
        $response = $this->client->request('POST', self::BUDGET_API_URL, [
            'json' => [
                'periodType' => 'monthly',
                'startDate' => '2024-03-01',
                'endDate' => '2024-03-31',
                'name' => 'March 2024',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertEquals('March 2024', $content['name']);
        self::assertEquals('monthly', $content['periodType']);
        self::assertArrayHasKey('id', $content);
        self::assertEmpty($content['lines']);
    }

    public function testCreateBudgetWithoutNameDefaultsToNull(): void
    {
        $response = $this->client->request('POST', self::BUDGET_API_URL, [
            'json' => [
                'periodType' => 'yearly',
                'startDate' => '2024-01-01',
                'endDate' => '2024-12-31',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertFalse(isset($content['name']) && $content['name'] !== null, 'Name should be null or absent');
        self::assertEquals('yearly', $content['periodType']);
    }

    public function testCreateBudgetCopiesLinesFromSource(): void
    {
        $source = $this->findBudget('January 2021');

        $response = $this->client->request('POST', self::BUDGET_API_URL, [
            'json' => [
                'periodType' => 'monthly',
                'startDate' => '2024-02-01',
                'endDate' => '2024-02-29',
                'copiedFromId' => $source->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertCount(5, $content['lines']);

        $amounts = array_map('floatval', array_column($content['lines'], 'plannedAmount'));
        sort($amounts);
        self::assertEquals([300.0, 500.0, 800.0, 3000.0, 15000.0], $amounts);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Update (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    public function testUpdateBudgetName(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('PUT', self::BUDGET_API_URL . '/' . $budget->getId(), [
            'json' => [
                'name' => 'Updated Name',
                'periodType' => 'monthly',
                'startDate' => '2021-01-01',
                'endDate' => '2021-01-31',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('Updated Name', $content['name']);
    }

    public function testUpdateBudgetDates(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('PUT', self::BUDGET_API_URL . '/' . $budget->getId(), [
            'json' => [
                'periodType' => 'monthly',
                'startDate' => '2021-01-05',
                'endDate' => '2021-01-25',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('2021-01-05', substr($content['startDate'], 0, 10));
        self::assertEquals('2021-01-25', substr($content['endDate'], 0, 10));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Delete (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    public function testDeleteBudget(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $budgetId = $budget->getId();

        $this->client->request('DELETE', self::BUDGET_API_URL . '/' . $budgetId);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', self::BUDGET_API_URL . '/' . $budgetId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteBudgetCascadesLines(): void
    {
        $budget = $this->findBudget('January 2021');
        $budgetId = $budget->getId();
        $lineCount = $budget->getLines()->count();
        self::assertGreaterThan(0, $lineCount);

        $this->client->request('DELETE', self::BUDGET_API_URL . '/' . $budgetId);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $remaining = $this->em->getRepository(Budget::class)->find($budgetId);
        self::assertNull($remaining);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Lines (API Platform subresource)
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateLine(): void
    {
        $budget = $this->findBudget('January 2021');

        $category = $this->em->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => 'Debt']);
        self::assertNotNull($category);

        $response = $this->client->request(
            'POST',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines',
            [
                'json' => [
                    'categoryId' => $category->getId(),
                    'plannedAmount' => 250.00,
                    'plannedCurrency' => 'EUR',
                ],
            ],
        );
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertEquals($category->getId(), $content['categoryId']);
        self::assertEqualsWithDelta(250.00, (float) $content['plannedAmount'], 0.01);
        self::assertEquals('EUR', $content['plannedCurrency']);
        self::assertArrayHasKey('id', $content);
    }

    public function testUpdateLine(): void
    {
        $budget = $this->findBudget('January 2021');
        $line = $budget->getLines()->first();
        self::assertInstanceOf(BudgetLine::class, $line);

        $response = $this->client->request(
            'PUT',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines/' . $line->getId(),
            [
                'json' => [
                    'plannedAmount' => 999.00,
                    'plannedCurrency' => 'HUF',
                ],
            ],
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEqualsWithDelta(999.00, (float) $content['plannedAmount'], 0.01);
        self::assertEquals('HUF', $content['plannedCurrency']);
    }

    public function testDeleteLine(): void
    {
        $budget = $this->findBudget('January 2021');
        $line = $budget->getLines()->first();
        self::assertInstanceOf(BudgetLine::class, $line);
        $lineId = $line->getId();

        $this->client->request(
            'DELETE',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines/' . $lineId,
        );
        self::assertResponseStatusCodeSame(204);

        $response = $this->client->request('GET', self::BUDGET_API_URL . '/' . $budget->getId());
        $content = $response->toArray();
        $lineIds = array_column($content['lines'], 'id');
        self::assertNotContains($lineId, $lineIds);
    }

    public function testDeleteLineFromWrongBudgetReturns404(): void
    {
        $januaryBudget = $this->findBudget('January 2021');
        $yearBudget = $this->findBudget('Full Year 2021');
        $yearLine = $yearBudget->getLines()->first();
        self::assertInstanceOf(BudgetLine::class, $yearLine);

        $this->client->request(
            'DELETE',
            self::BUDGET_API_URL . '/' . $januaryBudget->getId() . '/lines/' . $yearLine->getId(),
        );
        self::assertResponseStatusCodeSame(404);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Analytics (BudgetController v2)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Analytics for Jan 2021 budget should return category actuals
     * matching the transactions loaded by TransactionFixtures.
     */
    public function testAnalyticsReturnsCorrectShape(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/analytics',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);

        // Jan 2021 has transactions from TransactionFixtures — data should be non-empty
        self::assertNotEmpty($content['data']);

        $item = $content['data'][0];
        self::assertArrayHasKey('categoryId', $item);
        self::assertArrayHasKey('convertedValues', $item);

        $convertedValue = reset($item['convertedValues']);
        self::assertArrayHasKey('income', $convertedValue);
        self::assertArrayHasKey('expense', $convertedValue);
    }

    public function testAnalyticsRequiresAuth(): void
    {
        $budget = $this->findBudget('January 2021');

        $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/analytics',
            ['headers' => ['authorization' => null]],
        );
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // historyAverages (BudgetController v2)
    // ──────────────────────────────────────────────────────────────────────────

    public function testHistoryAveragesRequiresAuth(): void
    {
        $budget = $this->findBudget('January 2021');
        $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/history-averages',
            ['headers' => ['authorization' => null]],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testHistoryAveragesReturnsExpectedStructure(): void
    {
        $budget = $this->findBudget('January 2021');
        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/history-averages?months=3',
        );

        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        self::assertArrayHasKey('data', $content);
        self::assertArrayHasKey('months', $content);
        self::assertArrayHasKey('from', $content);
        self::assertArrayHasKey('to', $content);
        self::assertSame(3, $content['months']);
        self::assertIsArray($content['data']);
    }

    /**
     * Regression: old code set $start = now()->subMonths($months), a mid-month date.
     * The fix aligns to calendar-month boundaries. Verify via the `from`/`to` fields.
     */
    public function testHistoryAveragesWindowAlignedToCalendarMonthBoundaries(): void
    {
        $budget = $this->findBudget('January 2021');

        foreach ([1, 3, 6] as $months) {
            $response = $this->client->request(
                'GET',
                self::BUDGET_V2_URL . '/' . $budget->getId() . "/history-averages?months=$months",
            );
            self::assertResponseIsSuccessful();
            $content = $response->toArray();

            // `from` must be the 1st day of a month (never mid-month)
            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-01$/',
                $content['from'],
                "months=$months: `from` must be the 1st of a month, got {$content['from']}"
            );

            // `to` must be the last day of a month
            $toDate = \Carbon\CarbonImmutable::parse($content['to']);
            self::assertTrue(
                $toDate->isSameDay($toDate->endOfMonth()),
                "months=$months: `to` must be the last day of a month, got {$content['to']}"
            );
        }
    }

    /**
     * Regression: old code did `$start = now()->endOfDay()->subMonths($months)`, which set the
     * query start to a mid-month day N months ago. The fix sets $start = startOfMonth(subMonths($months - 1)).
     *
     * This test verifies the endpoint does not crash and that `months` param is respected.
     */
    public function testHistoryAveragesMonthsParamIsRespected(): void
    {
        $budget = $this->findBudget('January 2021');

        foreach ([1, 3, 6, 12] as $months) {
            $response = $this->client->request(
                'GET',
                self::BUDGET_V2_URL . '/' . $budget->getId() . "/history-averages?months=$months",
            );
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * Verify boundary exactness: a transaction on the first day of the oldest window month
     * must appear; one on the last day of the previous month must not.
     * months=1 → window = exactly the current calendar month.
     */
    public function testHistoryAveragesIncludesFirstDayOfWindowAndExcludesDayBefore(): void
    {
        $budget = $this->findBudget('January 2021');
        $groceriesCategory = $this->em->getRepository(\App\Entity\ExpenseCategory::class)
            ->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        assert($groceriesCategory instanceof \App\Entity\ExpenseCategory);

        $thisMonthStart = \Carbon\CarbonImmutable::now()->startOfMonth();
        $previousMonthEnd = $thisMonthStart->subDay()->endOfDay();

        // Transaction inside window (current month, day 1)
        $this->createExpense(50.0, $this->accountCashEUR, $groceriesCategory, $thisMonthStart, 'hist-inside');
        // Transaction outside window (last day of previous month)
        $this->createExpense(99.0, $this->accountCashEUR, $groceriesCategory, $previousMonthEnd, 'hist-outside');
        $this->em->clear();

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/history-averages?months=1',
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $groceriesActuals = null;
        foreach ($content['data'] as $actual) {
            if ($actual['categoryId'] === $groceriesCategory->getId()) {
                $groceriesActuals = $actual;
                break;
            }
        }

        self::assertNotNull($groceriesActuals, 'Groceries actuals must appear for the current-month transaction.');

        $eurExpense = $groceriesActuals['convertedValues']['EUR']['expense'] ?? 0.0;
        self::assertEqualsWithDelta(50.0, $eurExpense, 0.01,
            'Only the current-month transaction (50 EUR) must appear; the previous-month one (99 EUR) must be excluded.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Edge cases: empty budget, custom period, multi-currency, notes
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetEmptyBudgetReturnsZeroLines(): void
    {
        $budget = $this->findBudget('Empty June 2020');

        $response = $this->client->request('GET', self::BUDGET_API_URL . '/' . $budget->getId());
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('Empty June 2020', $content['name']);
        self::assertEmpty($content['lines']);
    }

    public function testGetCustomPeriodBudget(): void
    {
        $budget = $this->findBudget('Q1 2021');

        $response = $this->client->request('GET', self::BUDGET_API_URL . '/' . $budget->getId());
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('custom', $content['periodType']);
        self::assertEquals('2021-01-01', substr($content['startDate'], 0, 10));
        self::assertEquals('2021-03-31', substr($content['endDate'], 0, 10));
        self::assertCount(4, $content['lines']);
    }

    public function testMultiCurrencyLinesReturnCorrectCurrency(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('GET', self::BUDGET_API_URL . '/' . $budget->getId());
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        $currencies = array_unique(array_column($content['lines'], 'plannedCurrency'));
        sort($currencies);
        self::assertEquals(['EUR', 'UAH'], $currencies);
    }

    public function testCreateLineWithNote(): void
    {
        $budget = $this->findBudget('Empty June 2020');

        $category = $this->em->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => 'Rent']);
        self::assertNotNull($category);

        $response = $this->client->request(
            'POST',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines',
            [
                'json' => [
                    'categoryId' => $category->getId(),
                    'plannedAmount' => 800.00,
                    'plannedCurrency' => 'EUR',
                    'note' => 'Monthly rent payment',
                ],
            ],
        );
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertEquals('Monthly rent payment', $content['note']);
    }

    public function testUpdateLineNote(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $lineWithNote = null;
        foreach ($budget->getLines() as $line) {
            if ($line->getNote() !== null) {
                $lineWithNote = $line;
                break;
            }
        }
        self::assertNotNull($lineWithNote, 'Yearly 2021 budget should have at least one line with a note');

        $response = $this->client->request(
            'PUT',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines/' . $lineWithNote->getId(),
            [
                'json' => [
                    'note' => 'Updated note text',
                ],
            ],
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('Updated note text', $content['note']);
    }

    public function testClearLineNote(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $lineWithNote = null;
        foreach ($budget->getLines() as $line) {
            if ($line->getNote() !== null) {
                $lineWithNote = $line;
                break;
            }
        }
        self::assertNotNull($lineWithNote);

        $response = $this->client->request(
            'PUT',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines/' . $lineWithNote->getId(),
            [
                'json' => [
                    'note' => null,
                ],
            ],
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        // API Platform omits null values by default (skip_null_values: true)
        self::assertTrue(
            !isset($content['note']) || $content['note'] === null,
            'Note must be null or absent from response',
        );
    }

    public function testCreateLineWithInvalidCategoryReturns422(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request(
            'POST',
            self::BUDGET_API_URL . '/' . $budget->getId() . '/lines',
            [
                'json' => [
                    'categoryId' => 999999,
                    'plannedAmount' => 100.00,
                    'plannedCurrency' => 'EUR',
                ],
            ],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnalyticsOnEmptyBudgetReturnsEmptyData(): void
    {
        $budget = $this->findBudget('Empty June 2020');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/analytics',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
    }

    public function testDailyAnalyticsReturnsCorrectShape(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/analytics/daily',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);
    }

    public function testUpdateLineOnWrongBudgetReturns404(): void
    {
        $januaryBudget = $this->findBudget('January 2021');
        $yearBudget = $this->findBudget('Full Year 2021');
        $yearLine = $yearBudget->getLines()->first();
        self::assertInstanceOf(BudgetLine::class, $yearLine);

        $this->client->request(
            'PUT',
            self::BUDGET_API_URL . '/' . $januaryBudget->getId() . '/lines/' . $yearLine->getId(),
            [
                'json' => [
                    'plannedAmount' => 100.00,
                ],
            ],
        );
        self::assertResponseStatusCodeSame(404);
    }
}
