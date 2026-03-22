<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\ExpenseCategory;
use App\Tests\BaseApiTestCase;

/**
 * API contract tests for Budget endpoints.
 *
 * Endpoints covered:
 *   GET    /api/budgets                            — list budgets
 *   POST   /api/budgets                            — create budget
 *   GET    /api/budgets/{id}                       — get single budget
 *   PUT    /api/budgets/{id}                       — update budget
 *   DELETE /api/budgets/{id}                       — delete budget
 *   POST   /api/budgets/{budgetId}/lines           — create budget line
 *   PUT    /api/budgets/{budgetId}/lines/{id}      — update budget line
 *   DELETE /api/budgets/{budgetId}/lines/{id}      — delete budget line
 *   GET    /api/v2/budgets/{id}/analytics           — budget analytics
 *   GET    /api/v2/budgets/{id}/analytics/daily     — daily budget analytics
 *   GET    /api/v2/budgets/{id}/history-averages    — historical averages
 *
 * Fixtures: BaseApiTestCase (shared accounts, categories, transactions)
 *
 * @group budget
 */
class BudgetControllerTest extends BaseApiTestCase
{
    /** API Platform handles Budget CRUD at /api/budgets */
    private const BUDGET_API_URL = '/api/budgets';

    /** BudgetController still handles analytics */
    private const BUDGET_V2_URL = '/api/v2/budgets';

    private function findBudget(string $name): Budget
    {
        $budget = $this->entityManager()->getRepository(Budget::class)->findOneBy(['name' => $name]);
        self::assertNotNull($budget, "Budget '$name' not found in fixtures");

        return $budget;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // List (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Entity\Budget
     *
     * @group smoke
     */
    public function testListRequiresAuth(): void
    {
        $this->client->request('GET', self::BUDGET_API_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Entity\Budget
     *
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

    /**
     * @covers \App\Entity\Budget
     */
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

    /**
     * @covers \App\Entity\Budget
     */
    public function testGetNonExistentBudgetReturns404(): void
    {
        $this->client->request('GET', self::BUDGET_API_URL . '/999999');
        self::assertResponseStatusCodeSame(404);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Create (API Platform)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
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

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
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
        self::assertFalse(isset($content['name']), 'Name should be null or absent');
        self::assertEquals('yearly', $content['periodType']);
    }

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
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

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
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

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
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

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
    public function testDeleteBudget(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $budgetId = $budget->getId();

        $this->client->request('DELETE', self::BUDGET_API_URL . '/' . $budgetId);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', self::BUDGET_API_URL . '/' . $budgetId);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @covers \App\DataPersister\BudgetDataPersister
     */
    public function testDeleteBudgetCascadesLines(): void
    {
        $budget = $this->findBudget('January 2021');
        $budgetId = $budget->getId();
        $lineCount = $budget->getLines()->count();
        self::assertGreaterThan(0, $lineCount);

        $this->client->request('DELETE', self::BUDGET_API_URL . '/' . $budgetId);
        self::assertResponseStatusCodeSame(204);

        $this->entityManager()->clear();
        $remaining = $this->entityManager()->getRepository(Budget::class)->find($budgetId);
        self::assertNull($remaining);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Lines (API Platform subresource)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
    public function testCreateLine(): void
    {
        $budget = $this->findBudget('January 2021');

        $category = $this->entityManager()->getRepository(ExpenseCategory::class)
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

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
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

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
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

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
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
     *
     * @covers \App\Controller\BudgetController::analytics
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

    /**
     * @covers \App\Controller\BudgetController::analytics
     */
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

    /**
     * @covers \App\Controller\BudgetController::historyAverages
     */
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

    /**
     * @covers \App\Controller\BudgetController::historyAverages
     */
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
        self::assertArrayHasKey('after', $content);
        self::assertArrayHasKey('before', $content);
        self::assertSame(3, $content['months']);
        self::assertIsArray($content['data']);
    }

    /**
     * Regression: old code set $start = now()->subMonths($months), a mid-month date.
     * The fix aligns to calendar-month boundaries. Verify via the `after`/`before` fields.
     *
     * @covers \App\Controller\BudgetController::historyAverages
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

            // `after` must be the 1st day of a month (never mid-month)
            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-01$/',
                $content['after'],
                "months=$months: `after` must be the 1st of a month, got {$content['after']}",
            );

            // `before` must be the last day of a month
            $beforeDate = \Carbon\CarbonImmutable::parse($content['before']);
            self::assertTrue(
                $beforeDate->isSameDay($beforeDate->endOfMonth()),
                "months=$months: `before` must be the last day of a month, got {$content['before']}",
            );
        }
    }

    /**
     * Regression: old code did `$start = now()->endOfDay()->subMonths($months)`, which set the
     * query start to a mid-month day N months ago. The fix sets $start = startOfMonth(subMonths($months - 1)).
     *
     * This test verifies the endpoint does not crash and that `months` param is respected.
     *
     * @covers \App\Controller\BudgetController::historyAverages
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
     *
     * @covers \App\Controller\BudgetController::historyAverages
     */
    public function testHistoryAveragesIncludesFirstDayOfWindowAndExcludesDayBefore(): void
    {
        $budget = $this->findBudget('January 2021');
        $groceriesCategory = $this->entityManager()->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        \assert($groceriesCategory instanceof ExpenseCategory);

        $thisMonthStart = \Carbon\CarbonImmutable::now()->startOfMonth();
        $previousMonthEnd = $thisMonthStart->subDay()->endOfDay();

        // Transaction inside window (current month, day 1)
        $this->createExpense(50.0, $this->accountCashEUR, $groceriesCategory, $thisMonthStart, 'hist-inside');
        // Transaction outside window (last day of previous month)
        $this->createExpense(99.0, $this->accountCashEUR, $groceriesCategory, $previousMonthEnd, 'hist-outside');
        $this->entityManager()->clear();

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

    /**
     * @covers \App\Entity\Budget
     */
    public function testGetEmptyBudgetReturnsZeroLines(): void
    {
        $budget = $this->findBudget('Empty June 2020');

        $response = $this->client->request('GET', self::BUDGET_API_URL . '/' . $budget->getId());
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('Empty June 2020', $content['name']);
        self::assertEmpty($content['lines']);
    }

    /**
     * @covers \App\Entity\Budget
     */
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

    /**
     * @covers \App\Entity\Budget
     */
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

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
    public function testCreateLineWithNote(): void
    {
        $budget = $this->findBudget('Empty June 2020');

        $category = $this->entityManager()->getRepository(ExpenseCategory::class)
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

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
    public function testUpdateLineNote(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $lineWithNote = null;
        foreach ($budget->getLines() as $line) {
            if (null !== $line->getNote()) {
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

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
    public function testClearLineNote(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $lineWithNote = null;
        foreach ($budget->getLines() as $line) {
            if (null !== $line->getNote()) {
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
            !isset($content['note']),
            'Note must be null or absent from response',
        );
    }

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
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

    /**
     * @covers \App\Controller\BudgetController::analytics
     */
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

    /**
     * @covers \App\Controller\BudgetController::analyticsDailyStats
     */
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

    // ──────────────────────────────────────────────────────────────────────────
    // Insights (BudgetController v2)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsRequiresAuth(): void
    {
        $budget = $this->findBudget('January 2021');

        $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/insights',
            ['headers' => ['authorization' => null]],
        );
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsReturnsCorrectShape(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/insights',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('outliers', $content);
        self::assertArrayHasKey('trends', $content);
        self::assertArrayHasKey('seasonal', $content);
        self::assertIsArray($content['outliers']);
        self::assertIsArray($content['trends']);
        self::assertIsArray($content['seasonal']);
    }

    /**
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsOnEmptyBudgetReturnsEmptyArrays(): void
    {
        $budget = $this->findBudget('Empty June 2020');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/insights',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEmpty($content['outliers']);
        self::assertIsArray($content['trends']);
        self::assertIsArray($content['seasonal']);
    }

    /**
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsOutlierItemHasExpectedFields(): void
    {
        // Use Rent category — fixtures have no Rent transactions in Jan 2021,
        // so our test data is the only input for this category group.
        $rentCategory = $this->entityManager()->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => 'Rent']);
        \assert($rentCategory instanceof ExpenseCategory);

        $budget = $this->findBudget('January 2021');

        // 3 normal + 1 extreme outlier in a clean category
        $this->createExpense(800.0, $this->accountCashEUR, $rentCategory, \Carbon\CarbonImmutable::parse('2021-01-02'), 'rent-normal-1');
        $this->createExpense(810.0, $this->accountCashEUR, $rentCategory, \Carbon\CarbonImmutable::parse('2021-01-05'), 'rent-normal-2');
        $this->createExpense(790.0, $this->accountCashEUR, $rentCategory, \Carbon\CarbonImmutable::parse('2021-01-10'), 'rent-normal-3');
        $this->createExpense(15000.0, $this->accountCashEUR, $rentCategory, \Carbon\CarbonImmutable::parse('2021-01-15'), 'rent-extreme-outlier');
        $this->entityManager()->clear();

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/insights',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();

        // Find our extreme outlier in results
        $extremeOutliers = array_filter(
            $content['outliers'],
            static fn (array $outlier) => $outlier['note'] === 'rent-extreme-outlier',
        );

        self::assertNotEmpty($extremeOutliers, 'Extreme outlier (15000 EUR vs ~800 EUR normal) must be detected');

        $outlier = reset($extremeOutliers);
        self::assertArrayHasKey('transactionId', $outlier);
        self::assertArrayHasKey('categoryId', $outlier);
        self::assertArrayHasKey('note', $outlier);
        self::assertArrayHasKey('executedAt', $outlier);
        self::assertArrayHasKey('amount', $outlier);
        self::assertArrayHasKey('convertedAmount', $outlier);
        self::assertArrayHasKey('median', $outlier);
        self::assertArrayHasKey('deviation', $outlier);
        self::assertGreaterThan(5.0, $outlier['deviation']);
    }

    /**
     * Verify the currency query parameter is accepted and changes the base currency used.
     *
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsAcceptsCurrencyParameter(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/insights?currency=USD',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('outliers', $content);
        self::assertArrayHasKey('trends', $content);
        self::assertArrayHasKey('seasonal', $content);
    }

    /**
     * Without the currency param, insights should default to EUR (no error).
     *
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsDefaultsToEurWhenNoCurrencyProvided(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/' . $budget->getId() . '/insights',
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('outliers', $content);
    }

    /**
     * @covers \App\Controller\BudgetController::insights
     */
    public function testInsightsOnNonExistentBudgetReturns404(): void
    {
        $this->client->request(
            'GET',
            self::BUDGET_V2_URL . '/999999/insights',
        );
        self::assertResponseStatusCodeSame(404);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ISOLATION — cross-user data must not be visible
    // ──────────────────────────────────────────────────────────────────────────

    public function testListBudgets_withOtherUserData_returnsOnlyOwnData(): void
    {
        $otherUser = $this->createOtherUser('budget_list');

        $otherBudget = new Budget();
        $otherBudget
            ->setName('Other User Budget')
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-01-31'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($otherBudget);
        $this->entityManager()->flush();

        $response = $this->client->request('GET', self::BUDGET_API_URL);
        self::assertResponseIsSuccessful();

        $identifiers = array_column($response->toArray(), 'id');
        self::assertNotContains(
            $otherBudget->getId(),
            $identifiers,
            'Other user\'s budget must not appear in the authenticated user\'s list.',
        );
    }

    public function testGetBudget_ownedByOtherUser_returns403(): void
    {
        $otherUser = $this->createOtherUser('budget_item');

        $otherBudget = new Budget();
        $otherBudget
            ->setName('Other User Budget Item')
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-01-31'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($otherBudget);
        $this->entityManager()->flush();
        $otherBudgetId = $otherBudget->getId();

        $this->client->request('GET', self::BUDGET_API_URL . '/' . $otherBudgetId);
        // security: 'object.getOwner() == user' on the Get operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnalytics_onBudgetOwnedByOtherUser_returns404(): void
    {
        $otherUser = $this->createOtherUser('budget_analytics');

        $otherBudget = new Budget();
        $otherBudget
            ->setName('Other User Analytics Budget')
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-01-31'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($otherBudget);
        $this->entityManager()->flush();
        $otherBudgetId = $otherBudget->getId();

        $this->client->request('GET', self::BUDGET_V2_URL . '/' . $otherBudgetId . '/analytics');
        self::assertResponseStatusCodeSame(404);
    }

    public function testInsights_onBudgetOwnedByOtherUser_returns404(): void
    {
        $otherUser = $this->createOtherUser('budget_insights');

        $otherBudget = new Budget();
        $otherBudget
            ->setName('Other User Insights Budget')
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-01-01'))
            ->setEndDate(new \DateTimeImmutable('2021-01-31'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($otherBudget);
        $this->entityManager()->flush();
        $otherBudgetId = $otherBudget->getId();

        $this->client->request('GET', self::BUDGET_V2_URL . '/' . $otherBudgetId . '/insights');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPutBudget_ownedByOtherUser_returns403(): void
    {
        $otherUser = $this->createOtherUser('budget_put');

        $otherBudget = new Budget();
        $otherBudget
            ->setName('Other User Budget Put')
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-02-01'))
            ->setEndDate(new \DateTimeImmutable('2021-02-28'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($otherBudget);
        $this->entityManager()->flush();
        $otherBudgetId = $otherBudget->getId();

        $this->client->request('PUT', self::BUDGET_API_URL . '/' . $otherBudgetId, [
            'json' => [
                'name' => 'Hacked Budget',
                'startDate' => '2021-02-01',
                'endDate' => '2021-02-28',
                'periodType' => Budget::PERIOD_MONTHLY,
            ],
        ]);
        // security: 'object.getOwner() == user' on the Put operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteBudget_ownedByOtherUser_returns403(): void
    {
        $otherUser = $this->createOtherUser('budget_delete');

        $otherBudget = new Budget();
        $otherBudget
            ->setName('Other User Budget Delete')
            ->setPeriodType(Budget::PERIOD_MONTHLY)
            ->setStartDate(new \DateTimeImmutable('2021-03-01'))
            ->setEndDate(new \DateTimeImmutable('2021-03-31'))
            ->setOwner($otherUser);
        $this->entityManager()->persist($otherBudget);
        $this->entityManager()->flush();
        $otherBudgetId = $otherBudget->getId();

        $this->client->request('DELETE', self::BUDGET_API_URL . '/' . $otherBudgetId);
        // security: 'object.getOwner() == user' on the Delete operation → 403 Access Denied
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @covers \App\DataPersister\BudgetLineDataPersister
     */
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
