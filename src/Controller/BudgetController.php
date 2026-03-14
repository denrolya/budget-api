<?php

namespace App\Controller;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Entity\Category;
use App\Repository\BudgetRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/budget', name: 'api_v2_budget_')]
class BudgetController extends AbstractFOSRestController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BudgetRepository      $budgetRepo,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Budget CRUD
    // ──────────────────────────────────────────────────────────────────────────

    #[Rest\View]
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): View
    {
        $budgets = $this->budgetRepo->findBy([], ['startDate' => 'DESC']);

        return $this->view([
            'data' => array_map(fn(Budget $b) => $b->toListArray(), $budgets),
        ]);
    }

    #[Rest\View]
    #[Route('/{id<\d+>}', name: 'item', methods: ['GET'])]
    public function item(Budget $budget): View
    {
        return $this->view($budget->toArray());
    }

    #[Rest\View]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): View
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $budget = new Budget();
        $budget->setPeriodType($data['periodType'] ?? Budget::PERIOD_MONTHLY);
        $budget->setStartDate(new \DateTimeImmutable($data['startDate']));
        $budget->setEndDate(new \DateTimeImmutable($data['endDate']));

        if (!empty($data['name'])) {
            $budget->setName($data['name']);
        }

        // Copy lines from another budget if requested
        if (!empty($data['copiedFromId'])) {
            $source = $this->budgetRepo->find((int) $data['copiedFromId']);
            if ($source) {
                foreach ($source->getLines() as $sourceLine) {
                    $line = new BudgetLine();
                    $line->setCategory($sourceLine->getCategory());
                    $line->setPlannedAmount($sourceLine->getPlannedAmount());
                    $line->setPlannedCurrency($sourceLine->getPlannedCurrency());
                    $budget->addLine($line);
                }
            }
        }

        $this->em->persist($budget);
        $this->em->flush();

        return $this->view($budget->toArray(), Response::HTTP_CREATED);
    }

    #[Rest\View]
    #[Route('/{id<\d+>}', name: 'update', methods: ['PUT'])]
    public function update(Budget $budget, Request $request): View
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $data)) {
            $budget->setName($data['name'] ?: null);
        }
        if (!empty($data['periodType'])) {
            $budget->setPeriodType($data['periodType']);
        }
        if (!empty($data['startDate'])) {
            $budget->setStartDate(new \DateTimeImmutable($data['startDate']));
        }
        if (!empty($data['endDate'])) {
            $budget->setEndDate(new \DateTimeImmutable($data['endDate']));
        }

        $this->em->flush();

        return $this->view($budget->toArray());
    }

    #[Rest\View]
    #[Route('/{id<\d+>}', name: 'delete', methods: ['DELETE'])]
    public function delete(Budget $budget): View
    {
        $this->em->remove($budget);
        $this->em->flush();

        return $this->view(null, Response::HTTP_NO_CONTENT);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Budget Line CRUD
    // ──────────────────────────────────────────────────────────────────────────

    #[Rest\View]
    #[Route('/{id<\d+>}/line', name: 'line_create', methods: ['POST'])]
    public function lineCreate(Budget $budget, Request $request): View
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $category = $this->em->getReference(Category::class, (int) $data['categoryId']);

        $line = new BudgetLine();
        $line->setCategory($category);
        $line->setPlannedAmount($data['plannedAmount'] ?? 0);
        $line->setPlannedCurrency($data['plannedCurrency'] ?? 'EUR');
        if (array_key_exists('note', $data)) {
            $line->setNote($data['note'] ?: null);
        }
        $budget->addLine($line);

        $this->em->persist($line);
        $this->em->flush();

        return $this->view($line->toArray(), Response::HTTP_CREATED);
    }

    #[Rest\View]
    #[Route('/{id<\d+>}/line/{lineId<\d+>}', name: 'line_update', methods: ['PUT'])]
    public function lineUpdate(Budget $budget, int $lineId, Request $request): View
    {
        $line = $this->findLine($budget, $lineId);
        $data = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('plannedAmount', $data)) {
            $line->setPlannedAmount($data['plannedAmount']);
        }
        if (!empty($data['plannedCurrency'])) {
            $line->setPlannedCurrency($data['plannedCurrency']);
        }
        if (array_key_exists('note', $data)) {
            $line->setNote($data['note'] ?: null);
        }

        $this->em->flush();

        return $this->view($line->toArray());
    }

    #[Rest\View]
    #[Route('/{id<\d+>}/line/{lineId<\d+>}', name: 'line_delete', methods: ['DELETE'])]
    public function lineDelete(Budget $budget, int $lineId): View
    {
        $line = $this->findLine($budget, $lineId);
        $this->em->remove($line);
        $this->em->flush();

        return $this->view(null, Response::HTTP_NO_CONTENT);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Analytics
    // ──────────────────────────────────────────────────────────────────────────

    #[Rest\View]
    #[Route('/{id<\d+>}/analytics', name: 'analytics', methods: ['GET'])]
    public function analytics(Budget $budget, TransactionRepository $transactionRepo): View
    {
        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end   = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        return $this->view([
            'data' => $transactionRepo->getActualsByCategoryForPeriod($start, $end),
        ]);
    }

    #[Rest\View]
    #[Route('/{id<\d+>}/analytics/daily', name: 'analytics_daily', methods: ['GET'])]
    public function analyticsDailyStats(Budget $budget, TransactionRepository $transactionRepo): View
    {
        $start = CarbonImmutable::instance($budget->getStartDate())->startOfDay();
        $end   = CarbonImmutable::instance($budget->getEndDate())->endOfDay();

        return $this->view([
            'data' => $transactionRepo->getCategoryDailyStatsForPeriod($start, $end),
        ]);
    }

    #[Rest\View]
    #[Route('/{id<\d+>}/history-averages', name: 'history_averages', methods: ['GET'])]
    public function historyAverages(Budget $budget, Request $request, TransactionRepository $transactionRepo): View
    {
        $months = max(1, (int) ($request->query->get('months', 6)));
        // Align to full calendar months so the query window matches the $monthSlots array exactly.
        // e.g. months=6 on 2026-03-12 → window: 2025-10-01 … 2026-03-31 (6 complete months).
        $end   = CarbonImmutable::now()->endOfMonth()->endOfDay();
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1)->startOfDay();

        $actuals = $transactionRepo->getActualsByCategoryForPeriod($start, $end);
        $activeMonths = $transactionRepo->getCategoryActiveMonths($start, $end);
        $byMonth      = $transactionRepo->getActualsByCategoryByMonth($start, $end);

        // Build ordered month slots oldest→newest: ['2025-09', '2025-10', ..., '2026-02']
        $monthSlots = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthSlots[] = CarbonImmutable::now()->subMonths($i)->format('Y-m');
        }
        $totalWeight = $months * ($months + 1) / 2; // sum of 1+2+...+N

        // Compute recency-weighted monthly prediction per category per currency.
        // Weight index 0 (oldest month) = 1, index N-1 (newest) = N.
        $predictedByCat = [];
        foreach ($byMonth as $catId => $monthData) {
            // Collect all currencies that appear for this category
            $currencies = [];
            foreach ($monthData as $monthCurrencies) {
                foreach (array_keys($monthCurrencies) as $cur) {
                    $currencies[$cur] = true;
                }
            }
            $predicted = [];
            foreach (array_keys($currencies) as $cur) {
                // Only weight active months (months that actually had transactions).
                // Then scale by frequency (activeMonths / totalMonths).
                // This produces an "expected value" per month:
                //   regular categories (6/6mo)  → barely discounted
                //   irregular ones (1/6mo)       → strongly discounted (÷6)
                $activeWeightSum = 0.0;
                $wIncome         = 0.0;
                $wExpense        = 0.0;
                $activeCount     = 0;

                foreach ($monthSlots as $idx => $month) {
                    $cv = $monthData[$month][$cur] ?? null;
                    if ($cv === null) {
                        continue; // missing month → skip; frequency factor handles the scaling
                    }
                    $weight           = $idx + 1;
                    $activeWeightSum += $weight;
                    $wIncome         += $cv['income']  * $weight;
                    $wExpense        += $cv['expense'] * $weight;
                    $activeCount++;
                }

                // Require at least 2 active months — single-occurrence items are too
                // irregular to budget for (e.g. one-time bonus, occasional repair).
                if ($activeCount < 2) {
                    continue;
                }

                // Expected monthly value = weighted avg over active months × occurrence probability
                $frequency = $activeCount / $months;

                $predicted[$cur] = [
                    'income'  => ($wIncome  / $activeWeightSum) * $frequency,
                    'expense' => ($wExpense / $activeWeightSum) * $frequency,
                ];
            }
            $predictedByCat[$catId] = $predicted;
        }

        foreach ($actuals as &$item) {
            $catId                   = $item['categoryId'];
            $item['activeMonths']    = $activeMonths[$catId] ?? 1;
            $item['predictedValues'] = $predictedByCat[$catId] ?? [];
        }
        unset($item);

        return $this->view([
            'data'   => $actuals,
            'months' => $months,
            'from'   => $start->format('Y-m-d'),
            'to'     => $end->format('Y-m-d'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function findLine(Budget $budget, int $lineId): BudgetLine
    {
        $line = $budget->getLines()->filter(fn(BudgetLine $l) => $l->getId() === $lineId)->first();

        if (!$line instanceof BudgetLine) {
            throw $this->createNotFoundException("Budget line $lineId not found in budget {$budget->getId()}.");
        }

        return $line;
    }
}
