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
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testValueByPeriodWithoutArguments
     * @tested testValueByPeriodWithBeforeAndAfter
     * @tested testValueByPeriodWithOneDayInterval
     * @tested testValueByPeriodWithOneWeekInterval
     * @tested testValueByPeriodWithOneMonthInterval
     * @tested testValueByPeriodWithCustomInterval
     * @tested testValueByPeriodWithBooleanInterval
     * @tested testValueByPeriodWithWrongBeforeAndAfter
     * @tested testValueByPeriodWithNonExistentCategoryReturnsZero
     * @tested testValueByPeriod_typeExpense_returnsOnlyExpenseValues
     * @tested testValueByPeriod_typeIncome_returnsOnlyIncomeValues
     * @tested testValueByPeriod_accountsFilter_restrictsToAccount
     * @tested testValueByPeriod_emptyRange_returnsZeros
     * @tested testValueByPeriod_withoutAuth_returns401
     */
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
        $expandedCategories = $categories !== []
            ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type)
            : [];

        return $this->view(
            $statisticsManager->calculateTransactionsValueByPeriod(
                period: CarbonPeriod::create($after, $interval, $before)->excludeEndDate(),
                type: $type,
                // [0] sentinel: categories were requested but expansion found nothing → force zero results
                categories: $categories !== [] ? ($expandedCategories ?: [0]) : [],
                accounts: $accounts
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: false, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/tree', name: 'category_tree', methods: ['get'])]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testCategoryTreeWithBeforeAndAfter
     * @tested testCategoryTreeWithBeforeAfterAndType
     * @tested testCategoryTree_withoutAuth_returns401
     */
    public function categoryTree(
        TransactionRepository $transactionRepo,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this month')] CarbonImmutable $before,
        string $type,
    ): View {
        $transactions = $transactionRepo->getList(after: $after, before: $before, type: $type);

        return $this->view(
            $statisticsManager->generateCategoryTreeWithValues(
                transactions: $transactions,
                type: $type,
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'interval', default: '1 month', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/timeline', name: 'category_timeline', methods: ['get'])]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testCategoryTimelineWithoutCategoriesDoesNotCrash
     * @tested testCategoryTimelineWithCategoryFilterReturnsCategoryData
     */
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
            categories: ($categories !== null && $categories !== []) ? $categoryRepo->getCategoriesWithDescendantsByType($categories) : $categories,
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
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testAccountDistribution_returnsCorrectShape
     * @tested testAccountDistribution_incomeType_returnsDistribution
     * @tested testAccountDistribution_withoutAuth_returns401
     */
    public function accountDistribution(
        ManagerRegistry $doctrine,
        StatisticsManager $statisticsManager,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this year')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this year')] CarbonImmutable $before,
        string $type,
    ): View {
        $this->disableSoftDeletable();
        /** @var TransactionRepository $repo */
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
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testByWeekdays_returnsCorrectShape
     * @tested testByWeekdays_withoutAuth_returns401
     */
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
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testTopValueCategory_returnsCorrectShape
     * @tested testTopValueCategory_withoutAuth_returns401
     */
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
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testAvg_returnsCorrectShape
     * @tested testAvg_withTypeFilter_returnsFilteredAverage
     * @tested testAvg_withAccountsFilter_restrictsResults
     * @tested testAvg_withoutAuth_returns401
     */
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
        $expandedCategories = $categories !== []
            ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type)
            : [];

        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            type: $type,
            // [0] sentinel: categories were requested but expansion found nothing → force zero results
            categories: $categories !== [] ? ($expandedCategories ?: [0]) : $categories,
            accounts: $accounts,
        );

        return $this->view(
            $statisticsManager->averageByPeriod($transactions, new CarbonPeriod($after, $interval, $before))
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'accounts', description: 'Filter by accounts', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'excludedCategories', description: 'Exclude categories', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'currencies', description: 'Filter by currencies', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'isDraft', requirements: '^(0|1|true|false)$', default: null, description: 'true=only draft, false=only non-draft, null=all', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'note', description: 'Search substring in note', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'amount[gte]', description: 'Amount >= value (numeric)', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'amount[lte]', description: 'Amount <= value (numeric)', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'affectingProfit', requirements: '^(0|1|true|false)$', default: false, description: 'Only profit-affecting transactions', nullable: true, allowBlank: false)]
    #[Rest\View]
    #[Route('/daily', name: 'daily_stats', methods: ['get'])]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     * @tested testDaily_returnsCorrectShape
     * @tested testDaily_withTypeFilter_returnsFilteredData
     * @tested testDaily_withAccountsFilter_restrictsResults
     * @tested testDaily_emptyRange_returnsEmptyData
     * @tested testDaily_withoutAuth_returns401
     */
    public function dailyStats(
        Request $request,
        TransactionRepository $transactionRepo,
        #[MapCarbonDate(format: 'Y-m-d', default: '-1 year')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'now')] CarbonImmutable $before,
        ?string $type = null,
        ?array $accounts = null,
        ?array $categories = null,
        ?array $excludedCategories = null,
        ?array $currencies = null,
        ?bool $isDraft = null,
        ?string $note = null,
        bool $affectingProfit = false,
    ): View {
        $amount    = $request->query->all('amount');
        $amountGte = isset($amount['gte']) && is_numeric($amount['gte']) ? (float) $amount['gte'] : null;
        $amountLte = isset($amount['lte']) && is_numeric($amount['lte']) ? (float) $amount['lte'] : null;

        $note = (is_string($note) && trim($note) !== '') ? trim($note) : null;

        return $this->view([
            'data' => $transactionRepo->countByDay(
                after: $after->startOfDay(),
                before: $before->endOfDay(),
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
