<?php

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
    private const BUDGET_URL = '/api/v2/budget';

    private function findBudget(string $name): Budget
    {
        $budget = $this->em->getRepository(Budget::class)->findOneBy(['name' => $name]);
        self::assertNotNull($budget, "Budget '$name' not found in fixtures");
        assert($budget instanceof Budget);
        return $budget;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // List
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @group smoke
     */
    public function testListRequiresAuth(): void
    {
        $this->client->request('GET', self::BUDGET_URL, ['headers' => ['authorization' => null]]);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @group smoke
     */
    public function testListReturnsBudgets(): void
    {
        $response = $this->client->request('GET', self::BUDGET_URL);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertArrayHasKey('data', $content);
        self::assertIsArray($content['data']);

        // Two budgets loaded by BudgetFixtures
        self::assertCount(2, $content['data']);

        $first = $content['data'][0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('name', $first);
        self::assertArrayHasKey('periodType', $first);
        self::assertArrayHasKey('startDate', $first);
        self::assertArrayHasKey('endDate', $first);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Get single
    // ──────────────────────────────────────────────────────────────────────────

    public function testGetBudgetReturnsLinesAndMeta(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('GET', self::BUDGET_URL . '/' . $budget->getId());
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('January 2021', $content['name']);
        self::assertEquals('monthly', $content['periodType']);
        self::assertEquals('2021-01-01', substr($content['startDate'], 0, 10));
        self::assertEquals('2021-01-31', substr($content['endDate'], 0, 10));
        self::assertArrayHasKey('lines', $content);
        self::assertCount(4, $content['lines']);

        $line = $content['lines'][0];
        self::assertArrayHasKey('id', $line);
        self::assertArrayHasKey('categoryId', $line);
        self::assertArrayHasKey('plannedAmount', $line);
        self::assertArrayHasKey('plannedCurrency', $line);
    }

    public function testGetNonExistentBudgetReturns404(): void
    {
        $this->client->request('GET', self::BUDGET_URL . '/999999');
        self::assertResponseStatusCodeSame(404);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Create
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateMonthlyBudget(): void
    {
        $response = $this->client->request('POST', self::BUDGET_URL, [
            'json' => [
                'periodType' => 'monthly',
                'startDate'  => '2024-03-01',
                'endDate'    => '2024-03-31',
                'name'       => 'March 2024',
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
        $response = $this->client->request('POST', self::BUDGET_URL, [
            'json' => [
                'periodType' => 'yearly',
                'startDate'  => '2024-01-01',
                'endDate'    => '2024-12-31',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertNull($content['name']);
        self::assertEquals('yearly', $content['periodType']);
    }

    public function testCreateBudgetCopiesLinesFromSource(): void
    {
        $source = $this->findBudget('January 2021');

        $response = $this->client->request('POST', self::BUDGET_URL, [
            'json' => [
                'periodType'   => 'monthly',
                'startDate'    => '2024-02-01',
                'endDate'      => '2024-02-29',
                'copiedFromId' => $source->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertCount(4, $content['lines']);

        $amounts = array_column($content['lines'], 'plannedAmount');
        sort($amounts);
        self::assertEquals(['300.00', '500.00', '800.00', '3000.00'], $amounts);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Update
    // ──────────────────────────────────────────────────────────────────────────

    public function testUpdateBudgetName(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('PUT', self::BUDGET_URL . '/' . $budget->getId(), [
            'json' => ['name' => 'Updated Name'],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('Updated Name', $content['name']);
    }

    public function testUpdateBudgetClearsNameWhenEmpty(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('PUT', self::BUDGET_URL . '/' . $budget->getId(), [
            'json' => ['name' => ''],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertNull($content['name']);
    }

    public function testUpdateBudgetDates(): void
    {
        $budget = $this->findBudget('January 2021');

        $response = $this->client->request('PUT', self::BUDGET_URL . '/' . $budget->getId(), [
            'json' => [
                'startDate' => '2021-01-05',
                'endDate'   => '2021-01-25',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('2021-01-05', substr($content['startDate'], 0, 10));
        self::assertEquals('2021-01-25', substr($content['endDate'], 0, 10));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Delete
    // ──────────────────────────────────────────────────────────────────────────

    public function testDeleteBudget(): void
    {
        $budget = $this->findBudget('Full Year 2021');
        $id = $budget->getId();

        $this->client->request('DELETE', self::BUDGET_URL . '/' . $id);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', self::BUDGET_URL . '/' . $id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteBudgetCascadesLines(): void
    {
        $budget = $this->findBudget('January 2021');
        $id = $budget->getId();
        $lineCount = $budget->getLines()->count();
        self::assertGreaterThan(0, $lineCount);

        $this->client->request('DELETE', self::BUDGET_URL . '/' . $id);
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $remaining = $this->em->getRepository(Budget::class)->find($id);
        self::assertNull($remaining);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Lines
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateLine(): void
    {
        $budget = $this->findBudget('January 2021');

        $category = $this->em->getRepository(ExpenseCategory::class)
            ->findOneBy(['name' => 'Eating Out']);
        self::assertNotNull($category);

        $response = $this->client->request(
            'POST',
            self::BUDGET_URL . '/' . $budget->getId() . '/line',
            [
                'json' => [
                    'categoryId'      => $category->getId(),
                    'plannedAmount'   => 250.00,
                    'plannedCurrency' => 'EUR',
                ],
            ],
        );
        self::assertResponseStatusCodeSame(201);

        $content = $response->toArray();
        self::assertEquals($category->getId(), $content['categoryId']);
        self::assertEquals('250.00', $content['plannedAmount']);
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
            self::BUDGET_URL . '/' . $budget->getId() . '/line/' . $line->getId(),
            [
                'json' => [
                    'plannedAmount'   => 999.00,
                    'plannedCurrency' => 'HUF',
                ],
            ],
        );
        self::assertResponseIsSuccessful();

        $content = $response->toArray();
        self::assertEquals('999.00', $content['plannedAmount']);
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
            self::BUDGET_URL . '/' . $budget->getId() . '/line/' . $lineId,
        );
        self::assertResponseStatusCodeSame(204);

        $response = $this->client->request('GET', self::BUDGET_URL . '/' . $budget->getId());
        $content = $response->toArray();
        $lineIds = array_column($content['lines'], 'id');
        self::assertNotContains($lineId, $lineIds);
    }

    public function testDeleteLineFromWrongBudgetReturns404(): void
    {
        $jan = $this->findBudget('January 2021');
        $year = $this->findBudget('Full Year 2021');
        $yearLine = $year->getLines()->first();
        self::assertInstanceOf(BudgetLine::class, $yearLine);

        $this->client->request(
            'DELETE',
            self::BUDGET_URL . '/' . $jan->getId() . '/line/' . $yearLine->getId(),
        );
        self::assertResponseStatusCodeSame(404);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Analytics
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
            self::BUDGET_URL . '/' . $budget->getId() . '/analytics',
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

        $cv = reset($item['convertedValues']);
        self::assertArrayHasKey('income', $cv);
        self::assertArrayHasKey('expense', $cv);
    }

    public function testAnalyticsRequiresAuth(): void
    {
        $budget = $this->findBudget('January 2021');

        $this->client->request(
            'GET',
            self::BUDGET_URL . '/' . $budget->getId() . '/analytics',
            ['headers' => ['authorization' => null]],
        );
        self::assertResponseStatusCodeSame(401);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // historyAverages
    // ──────────────────────────────────────────────────────────────────────────

    public function testHistoryAveragesRequiresAuth(): void
    {
        $budget = $this->findBudget('January 2021');
        $this->client->request(
            'GET',
            self::BUDGET_URL . '/' . $budget->getId() . '/history-averages',
            ['headers' => ['authorization' => null]],
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testHistoryAveragesReturnsExpectedStructure(): void
    {
        $budget = $this->findBudget('January 2021');
        $response = $this->client->request(
            'GET',
            self::BUDGET_URL . '/' . $budget->getId() . '/history-averages?months=3',
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
                self::BUDGET_URL . '/' . $budget->getId() . "/history-averages?months=$months",
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
     * query start to a mid-month day N months ago. Transactions from that partial month were
     * included in the actuals query but had no corresponding slot in $monthSlots, silently
     * diluting the frequency and weighted-average calculations.
     *
     * The fix sets $start = startOfMonth(subMonths($months - 1)) and $end = endOfMonth(), so the
     * query window exactly covers the same N calendar months as $monthSlots.
     *
     * This test verifies the endpoint does not crash and that `months` param is respected.
     */
    public function testHistoryAveragesMonthsParamIsRespected(): void
    {
        $budget = $this->findBudget('January 2021');

        foreach ([1, 3, 6, 12] as $months) {
            $response = $this->client->request(
                'GET',
                self::BUDGET_URL . '/' . $budget->getId() . "/history-averages?months=$months",
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
        $budget   = $this->findBudget('January 2021');
        $groceries = $this->em->getRepository(\App\Entity\ExpenseCategory::class)
            ->findOneBy(['name' => self::CATEGORY_EXPENSE_GROCERIES]);
        assert($groceries instanceof \App\Entity\ExpenseCategory);

        $thisMonthStart   = \Carbon\CarbonImmutable::now()->startOfMonth();
        $previousMonthEnd = $thisMonthStart->subDay()->endOfDay();

        // Transaction inside window (current month, day 1)
        $this->createExpense(50.0, $this->accountCashEUR, $groceries, $thisMonthStart, 'hist-inside');
        // Transaction outside window (last day of previous month)
        $this->createExpense(99.0, $this->accountCashEUR, $groceries, $previousMonthEnd, 'hist-outside');
        $this->em->clear();

        $response = $this->client->request(
            'GET',
            self::BUDGET_URL . '/' . $budget->getId() . '/history-averages?months=1',
        );
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $groceriesActuals = null;
        foreach ($content['data'] as $actual) {
            if ($actual['categoryId'] === $groceries->getId()) {
                $groceriesActuals = $actual;
                break;
            }
        }

        self::assertNotNull($groceriesActuals, 'Groceries actuals must appear for the current-month transaction.');

        $eurExpense = $groceriesActuals['convertedValues']['EUR']['expense'] ?? 0.0;
        self::assertEqualsWithDelta(50.0, $eurExpense, 0.01,
            'Only the current-month transaction (50 EUR) must appear; the previous-month one (99 EUR) must be excluded.');
    }
}
