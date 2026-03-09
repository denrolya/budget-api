<?php

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Attribute\MapCarbonInterval;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Service\StatisticsManager;
use App\Traits\SoftDeletableTogglerController;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/statistics', name: 'api_v2_statistics_')]
class StatisticsController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'interval', default: false, nullable: false, allowBlank: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/value-by-period', name: 'value_by_period', methods: ['get'])]
    public function value(
        EntityManagerInterface $em,
        CategoryRepository $categoryRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this month')] CarbonImmutable $before,
        #[MapCarbonInterval] ?CarbonInterval $interval,
        ?string $type,
        array $accounts,
        array $categories,
    ): View {
        return $this->view(
            $statisticsManager->calculateTransactionsValueByPeriod(
                period: CarbonPeriod::create($after, $interval, $before)->excludeEndDate(),
                type: $type,
                categories: $categories !== [] ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type) : $categories,
                accounts: $accounts
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: false, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/tree', name: 'category_tree', methods: ['get'])]
    public function categoryTree(
        TransactionRepository $transactionRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this month')] CarbonImmutable $before,
        string $type,
    ): View {
        $transactions = $transactionRepo->getList(after: $after, before: $before, type: $type);

        return $this->view(
            $statisticsManager->generateCategoryTreeWithValues(transactions: $transactions, type: $type)
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'interval', default: '1 month', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/timeline', name: 'category_timeline', methods: ['get'])]
    public function categoryTimeline(
        TransactionRepository $transactionRepo,
        CategoryRepository $categoryRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this month')] CarbonImmutable $before,
        #[MapCarbonInterval(default: '1 month')] CarbonInterval $interval,
        ?array $categories,
    ): View {
        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            categories: $categories !== [] ? $categoryRepo->getCategoriesWithDescendantsByType($categories) : $categories,
            affectingProfitOnly: false,
        );

        return $this->view(
            $statisticsManager->generateCategoriesOnTimelineStatistics(
                new CarbonPeriod($after, $interval, $before),
                $categories,
                $transactions,
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['account:collection:read'])]
    #[Route('/account-distribution', name: 'account_distribution', methods: ['get'])]
    public function accountDistribution(
        ManagerRegistry $doctrine,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this year')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this year')] CarbonImmutable $before,
        string $type,
    ): View {
        $this->disableSoftDeletable();
        $repo = $doctrine->getRepository(($type === 'expense') ? Expense::class : Income::class);

        return $this->view(
            $statisticsManager->generateAccountDistributionStatistics(
                $repo->getList(after: $after, before: $before, type: $type)
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: Transaction::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/by-weekdays', name: 'by_weekdays', methods: ['get'])]
    public function transactionsValueByWeekdays(
        TransactionRepository $transactionRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this year')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this year')] CarbonImmutable $before,
        string $type,
    ): View {
        return $this->view(
            $statisticsManager->generateTransactionsValueByCategoriesByWeekdays(
                $transactionRepo->getList(after: $after, before: $before, type: $type)
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: Transaction::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/top-value-category', name: 'top_value_category', methods: ['get'])]
    public function topValueCategory(
        TransactionRepository $transactionRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this year')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this year')] CarbonImmutable $before,
        string $type,
    ): View {
        return $this->view(
            $statisticsManager->generateTopValueCategoryStatistics(
                $transactionRepo->getList(after: $after, before: $before, type: $type)
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'interval', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/avg', name: 'average', methods: ['get'])]
    public function average(
        TransactionRepository $transactionRepo,
        CategoryRepository $categoryRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this month')] CarbonImmutable $before,
        #[MapCarbonInterval] ?CarbonInterval $interval,
        ?string $type,
        array $accounts,
        array $categories,
    ): View {
        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            type: $type,
            categories: $categories !== [] ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type) : $categories,
            accounts: $accounts,
        );

        return $this->view(
            $statisticsManager->averageByPeriod($transactions, new CarbonPeriod($after, $interval, $before))
        );
    }

    /**
     * Returns transaction counts and converted volumes grouped by calendar day,
     * applying the full set of transaction filters (same params as the listing API).
     *
     * Query params: after, before, accounts[], categories[], excludedCategories[],
     *   type, currencies[], isDraft, note, amount[gte], amount[lte], affectingProfit
     */
    #[Rest\View]
    #[Route('/daily', name: 'daily_stats', methods: ['get'])]
    public function dailyStats(Request $request, TransactionRepository $transactionRepo): View
    {
        $after  = CarbonImmutable::parse($request->query->get('after',  '-1 year'))->startOfDay();
        $before = CarbonImmutable::parse($request->query->get('before', 'now'))->endOfDay();

        $toIntArray = static function (mixed $raw): ?array {
            if (empty($raw)) {
                return null;
            }
            $ids = array_values(array_map('intval', array_filter((array) $raw, 'is_numeric')));
            return $ids ?: null;
        };

        $accounts           = $toIntArray($request->query->all()['accounts'] ?? []);
        $categories         = $toIntArray($request->query->all()['categories'] ?? []);
        $excludedCategories = $toIntArray($request->query->all()['excludedCategories'] ?? []);

        $rawType = $request->query->get('type');
        $type    = in_array($rawType, ['income', 'expense'], true) ? $rawType : null;

        $rawCurrencies = (array) ($request->query->all()['currencies'] ?? []);
        $currencies    = array_values(array_filter(array_map('strtoupper', $rawCurrencies)));
        $currencies    = $currencies ?: null;

        $isDraftRaw = $request->query->get('isDraft');
        $isDraft    = $isDraftRaw !== null ? filter_var($isDraftRaw, FILTER_VALIDATE_BOOLEAN) : null;

        $note      = $request->query->get('note') ?: null;
        $amountGte = ($v = $request->query->get('amount[gte]')) !== null ? (float) $v : null;
        $amountLte = ($v = $request->query->get('amount[lte]')) !== null ? (float) $v : null;

        $affectingProfit = filter_var($request->query->get('affectingProfit', false), FILTER_VALIDATE_BOOLEAN);

        return $this->view([
            'data' => $transactionRepo->countByDayForFilters(
                after: $after,
                before: $before,
                affectingProfitOnly: $affectingProfit,
                type: $type,
                categories: $categories,
                accounts: $accounts,
                excludedCategories: $excludedCategories,
                isDraft: $isDraft,
                note: $note,
                amountGte: $amountGte,
                amountLte: $amountLte,
                currencies: $currencies,
            ),
        ]);
    }
}
