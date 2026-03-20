<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Budget;
use App\Repository\BudgetRepository;
use App\Repository\TransactionRepository;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/budgets', name: 'api_v2_budgets_')]
#[OA\Tag(name: 'Budget')]
class BudgetController extends AbstractFOSRestController
{
    // ──────────────────────────────────────────────────────────────────────────
    // Analytics
    // ──────────────────────────────────────────────────────────────────────────

    #[Rest\View]
    #[Route('/{id<\d+>}/analytics', name: 'analytics', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v2/budgets/{id}/analytics',
        summary: 'Budget actuals by category',
        description: 'Returns actual income and expense totals grouped by category for the budget period.',
        security: [['bearerAuth' => []]],
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Budget ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category actuals',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                        description: 'Per-category aggregate',
                        type: 'object',
                    )),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Budget not found'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\BudgetControllerTest
     *
     * @tested testAnalyticsReturnsCorrectShape
     * @tested testAnalyticsRequiresAuth
     * @tested testAnalyticsOnEmptyBudgetReturnsEmptyData
     */
    public function analytics(Budget $budget, TransactionRepository $transactionRepository): View
    {
        $this->requireBudgetOwnership($budget);

        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        return $this->view([
            'data' => $transactionRepository->getActualsByCategoryForPeriod($start, $end),
        ]);
    }

    #[Rest\View]
    #[Route('/{id<\d+>}/analytics/daily', name: 'analytics_daily', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v2/budgets/{id}/analytics/daily',
        summary: 'Budget daily category stats',
        description: 'Returns per-day category breakdown of transactions within the budget period.',
        security: [['bearerAuth' => []]],
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Budget ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daily category stats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Budget not found'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\BudgetControllerTest
     *
     * @tested testDailyAnalyticsReturnsCorrectShape
     */
    public function analyticsDailyStats(Budget $budget, TransactionRepository $transactionRepository): View
    {
        $this->requireBudgetOwnership($budget);

        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        return $this->view([
            'data' => $transactionRepository->getCategoryDailyStatsForPeriod($start, $end),
        ]);
    }

    #[Rest\QueryParam(name: 'months', requirements: '^[1-9][0-9]*$', default: 6, description: 'Number of months to analyze')]
    #[Rest\View]
    #[Route('/{id<\d+>}/history-averages', name: 'history_averages', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v2/budgets/{id}/history-averages',
        summary: 'Budget category history averages with predictions',
        description: 'Returns per-category recency-weighted average spend/income over the last N calendar months, with a prediction for the upcoming month. Requires at least 2 active months per category to produce a prediction.',
        security: [['bearerAuth' => []]],
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Budget ID', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'months', in: 'query', required: false, description: 'Number of calendar months to look back (default: 6)', schema: new OA\Schema(type: 'integer', minimum: 1, default: 6)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'History averages with predictions',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'months', type: 'integer', example: 6),
                    new OA\Property(property: 'after', type: 'string', format: 'date', example: '2024-01-01'),
                    new OA\Property(property: 'before', type: 'string', format: 'date', example: '2024-06-30'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Budget not found'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\BudgetControllerTest
     *
     * @tested testHistoryAveragesRequiresAuth
     * @tested testHistoryAveragesReturnsExpectedStructure
     * @tested testHistoryAveragesWindowAlignedToCalendarMonthBoundaries
     * @tested testHistoryAveragesMonthsParamIsRespected
     * @tested testHistoryAveragesIncludesFirstDayOfWindowAndExcludesDayBefore
     */
    public function historyAverages(
        Budget $budget,
        TransactionRepository $transactionRepository,
        int $months = 6,
    ): View {
        $this->requireBudgetOwnership($budget);

        $months = max(1, $months);
        $end = CarbonImmutable::now()->endOfMonth()->endOfDay();
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1)->startOfDay();

        $actuals = $transactionRepository->getActualsByCategoryForPeriod($start, $end);
        $activeMonths = $transactionRepository->getCategoryActiveMonths($start, $end);
        $byMonth = $transactionRepository->getActualsByCategoryByMonth($start, $end);

        $monthSlots = $this->buildMonthSlots($months);
        $predictedByCategory = $this->computePredictions($byMonth, $monthSlots, $months);

        foreach ($actuals as &$item) {
            $categoryId = $item['categoryId'];
            $item['activeMonths'] = $activeMonths[$categoryId] ?? 1;
            $item['predictedValues'] = $predictedByCategory[$categoryId] ?? [];
        }
        unset($item);

        return $this->view([
            'data' => $actuals,
            'months' => $months,
            'after' => $start->format('Y-m-d'),
            'before' => $end->format('Y-m-d'),
        ]);
    }

    #[Rest\QueryParam(name: 'currency', requirements: '^[A-Z]{3}$', default: 'EUR', description: 'Base currency for converted amounts')]
    #[Rest\View]
    #[Route('/{id<\d+>}/insights', name: 'insights', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v2/budgets/{id}/insights',
        summary: 'Budget insights: outliers, trends, and seasonal patterns',
        description: 'Returns statistical insights for the budget period — unusual transactions (MAD-based), category spending trends, and seasonal spending patterns.',
        security: [['bearerAuth' => []]],
        tags: ['Budget'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Budget ID', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'currency', in: 'query', required: false, description: 'Base currency for converted amounts (default: EUR)', schema: new OA\Schema(type: 'string', pattern: '^[A-Z]{3}$', default: 'EUR')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Budget insights',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'outliers', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'trends', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'seasonal', type: 'array', items: new OA\Items(type: 'object')),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Budget not found'),
        ],
    )]
    public function insights(Budget $budget, StatisticsManager $statisticsManager, string $currency = 'EUR'): View
    {
        $this->requireBudgetOwnership($budget);

        return $this->view($statisticsManager->computeBudgetInsights($budget, $currency));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Summaries (for sidebar)
    // ──────────────────────────────────────────────────────────────────────────

    #[Rest\View]
    #[Route('/summaries', name: 'summaries', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v2/budgets/summaries',
        summary: 'Lightweight summaries for all budgets',
        description: 'Returns per-budget totals (actual + planned expense/income) for sidebar display. Single query per budget.',
        security: [['bearerAuth' => []]],
        tags: ['Budget'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Budget summaries',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                ]),
            ),
        ],
    )]
    public function summaries(
        BudgetRepository $budgetRepository,
        TransactionRepository $transactionRepository,
        StatisticsManager $statisticsManager,
    ): View {
        $budgets = $budgetRepository->findBy(
            ['owner' => $this->getUser()],
            ['startDate' => 'DESC'],
        );

        $summaries = [];
        foreach ($budgets as $budget) {
            $summaries[] = $statisticsManager->computeBudgetSummary($budget, $transactionRepository);
        }

        return $this->view(['data' => $summaries]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function buildMonthSlots(int $months): array
    {
        $monthSlots = [];
        for ($index = $months - 1; $index >= 0; --$index) {
            $monthSlots[] = CarbonImmutable::now()->subMonths($index)->format('Y-m');
        }

        return $monthSlots;
    }

    /**
     * Compute recency-weighted monthly prediction per category per currency.
     * Weight index 0 (oldest month) = 1, index N-1 (newest) = N.
     * Requires at least 2 active months to produce a prediction.
     *
     * @param array<int, array<string, array<string, array{income: float, expense: float}>>> $byMonth
     * @param list<string> $monthSlots
     *
     * @return array<int, array<string, array{income: float, expense: float}>>
     */
    private function computePredictions(array $byMonth, array $monthSlots, int $months): array
    {
        $minimumActiveMonths = 2;
        $predictedByCategory = [];

        foreach ($byMonth as $categoryId => $monthData) {
            $currencies = $this->collectCurrencies($monthData);
            $predicted = [];

            foreach (array_keys($currencies) as $currency) {
                $activeWeightSum = 0.0;
                $weightedIncome = 0.0;
                $weightedExpense = 0.0;
                $activeCount = 0;

                foreach ($monthSlots as $index => $month) {
                    $currencyValues = $monthData[$month][$currency] ?? null;
                    if (null === $currencyValues) {
                        continue;
                    }
                    $weight = $index + 1;
                    $activeWeightSum += $weight;
                    $weightedIncome += $currencyValues['income'] * $weight;
                    $weightedExpense += $currencyValues['expense'] * $weight;
                    ++$activeCount;
                }

                if ($activeCount < $minimumActiveMonths) {
                    continue;
                }

                $frequency = $activeCount / $months;
                $predicted[$currency] = [
                    'income' => ($weightedIncome / $activeWeightSum) * $frequency,
                    'expense' => ($weightedExpense / $activeWeightSum) * $frequency,
                ];
            }

            $predictedByCategory[$categoryId] = $predicted;
        }

        return $predictedByCategory;
    }

    /**
     * @param array<string, array<string, array{income: float, expense: float}>> $monthData
     *
     * @return array<string, true>
     */
    private function collectCurrencies(array $monthData): array
    {
        $currencies = [];
        foreach ($monthData as $monthCurrencies) {
            foreach (array_keys($monthCurrencies) as $currency) {
                $currencies[$currency] = true;
            }
        }

        return $currencies;
    }

    private function requireBudgetOwnership(Budget $budget): void
    {
        $currentUser = $this->getUser();
        if (null === $currentUser || $budget->getOwner() !== $currentUser) {
            throw new NotFoundHttpException();
        }
    }
}
