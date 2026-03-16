<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Budget;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/budget', name: 'api_v2_budget_')]
class BudgetController extends AbstractFOSRestController
{
    // ──────────────────────────────────────────────────────────────────────────
    // Analytics
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @see \App\Tests\Controller\BudgetControllerTest
     * @tested testAnalyticsReturnsCorrectShape
     * @tested testAnalyticsRequiresAuth
     * @tested testAnalyticsOnEmptyBudgetReturnsEmptyData
     */
    #[Rest\View]
    #[Route('/{id<\d+>}/analytics', name: 'analytics', methods: ['GET'])]
    public function analytics(Budget $budget, TransactionRepository $transactionRepository): View
    {
        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        return $this->view([
            'data' => $transactionRepository->getActualsByCategoryForPeriod($start, $end),
        ]);
    }

    /**
     * @see \App\Tests\Controller\BudgetControllerTest
     * @tested testDailyAnalyticsReturnsCorrectShape
     */
    #[Rest\View]
    #[Route('/{id<\d+>}/analytics/daily', name: 'analytics_daily', methods: ['GET'])]
    public function analyticsDailyStats(Budget $budget, TransactionRepository $transactionRepository): View
    {
        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        return $this->view([
            'data' => $transactionRepository->getCategoryDailyStatsForPeriod($start, $end),
        ]);
    }

    /**
     * @see \App\Tests\Controller\BudgetControllerTest
     * @tested testHistoryAveragesRequiresAuth
     * @tested testHistoryAveragesReturnsExpectedStructure
     * @tested testHistoryAveragesWindowAlignedToCalendarMonthBoundaries
     * @tested testHistoryAveragesMonthsParamIsRespected
     * @tested testHistoryAveragesIncludesFirstDayOfWindowAndExcludesDayBefore
     */
    #[Rest\QueryParam(name: 'months', requirements: '^[1-9][0-9]*$', default: 6, description: 'Number of months to analyze')]
    #[Rest\View]
    #[Route('/{id<\d+>}/history-averages', name: 'history_averages', methods: ['GET'])]
    public function historyAverages(
        Budget $budget,
        TransactionRepository $transactionRepository,
        int $months = 6,
    ): View {
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

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function buildMonthSlots(int $months): array
    {
        $monthSlots = [];
        for ($index = $months - 1; $index >= 0; $index--) {
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
                    if ($currencyValues === null) {
                        continue;
                    }
                    $weight = $index + 1;
                    $activeWeightSum += $weight;
                    $weightedIncome += $currencyValues['income'] * $weight;
                    $weightedExpense += $currencyValues['expense'] * $weight;
                    $activeCount++;
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
}
