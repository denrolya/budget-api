<?php

declare(strict_types=1);

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
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/statistics', name: 'api_v2_statistics_')]
#[OA\Tag(name: 'Statistics')]
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
    #[OA\Get(
        path: '/api/v2/statistics/value-by-period',
        summary: 'Transaction value grouped by time period',
        description: 'Returns income and expense totals for each period slot between after and before. Used for charts. Supports category and account filters with automatic descendant expansion.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: first day of current month', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: last day of current month', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'interval', in: 'query', required: false, description: 'ISO 8601 interval (P1D, P1W, P1M)', schema: new OA\Schema(type: 'string', example: 'P1M')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'expense | income', schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
            new OA\Parameter(name: 'accounts[]', in: 'query', required: false, description: 'Filter by account IDs', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'categories[]', in: 'query', required: false, description: 'Filter by category IDs (descendants included)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Period value buckets',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'after', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'before', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'expense', type: 'number', format: 'float'),
                    new OA\Property(property: 'income', type: 'number', format: 'float'),
                ])),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
        $expandedCategories = [] !== $categories
            ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type)
            : [];

        return $this->view(
            $statisticsManager->calculateTransactionsValueByPeriod(
                period: CarbonPeriod::create($after, $interval, $before)->excludeEndDate(),
                type: $type,
                // [0] sentinel: categories were requested but expansion found nothing → force zero results
                categories: [] !== $categories ? ([] !== $expandedCategories ? $expandedCategories : [0]) : [],
                accounts: $accounts,
            ),
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: false, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/tree', name: 'category_tree', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/statistics/category/tree',
        summary: 'Category spending tree',
        description: 'Returns a hierarchical category tree with aggregated transaction values for the given period and type.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: true, description: 'expense | income', schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category tree with values'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
            ),
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'interval', default: '1 month', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/timeline', name: 'category_timeline', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/statistics/category/timeline',
        summary: 'Category values over a timeline',
        description: 'Returns per-interval totals for the given categories, enabling timeline/trend charts.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'interval', in: 'query', required: false, description: 'Period interval, default: 1 month', schema: new OA\Schema(type: 'string', default: '1 month')),
            new OA\Parameter(name: 'categories[]', in: 'query', required: false, description: 'Category IDs to include', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category timeline data'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
            categories: (null !== $categories && [] !== $categories) ? $categoryRepo->getCategoriesWithDescendantsByType($categories) : $categories,
            affectingProfitOnly: false,
        );

        return $this->view(
            $statisticsManager->generateCategoriesOnTimelineStatistics(
                new CarbonPeriod($after, $interval, $before),
                $categories,
                $transactions,
            ),
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['account:collection:read'])]
    #[Route('/account-distribution', name: 'account_distribution', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/statistics/account-distribution',
        summary: 'Transaction value distribution by account',
        description: 'Returns the share of total income or expense value per account for the given period.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: first day of current year', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: last day of current year', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'expense | income', schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account distribution data'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
        $repo = $doctrine->getRepository(('expense' === $type) ? Expense::class : Income::class);

        return $this->view(
            $statisticsManager->generateAccountDistributionStatistics(
                $repo->getList(after: $after, before: $before, type: $type),
            ),
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: Transaction::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/by-weekdays', name: 'by_weekdays', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/statistics/by-weekdays',
        summary: 'Transaction value by weekday',
        description: 'Returns aggregated income/expense values grouped by day of the week.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: first day of current year', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: last day of current year', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'expense | income, default: expense', schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Weekday distribution'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
                $transactionRepo->getList(after: $after, before: $before, type: $type),
            ),
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: Transaction::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/top-value-category', name: 'top_value_category', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/statistics/top-value-category',
        summary: 'Top spending/income categories',
        description: 'Returns categories ranked by total transaction value descending for the given period.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: first day of current year', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: last day of current year', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'expense | income, default: expense', schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Top value categories'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
                $transactionRepo->getList(after: $after, before: $before, type: $type),
            ),
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[Rest\QueryParam(name: 'interval', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/avg', name: 'average', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/statistics/avg',
        summary: 'Average transaction value per period',
        description: 'Returns the average income and expense per period interval over the given date range. Supports account and category filters.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'interval', in: 'query', required: false, description: 'ISO 8601 interval', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'expense | income', schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
            new OA\Parameter(name: 'accounts[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'categories[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Average value data'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
        $expandedCategories = [] !== $categories
            ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type)
            : [];

        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            type: $type,
            // [0] sentinel: categories were requested but expansion found nothing → force zero results
            categories: [] !== $categories ? ([] !== $expandedCategories ? $expandedCategories : [0]) : $categories,
            accounts: $accounts,
        );

        return $this->view(
            $statisticsManager->averageByPeriod($transactions, new CarbonPeriod($after, $interval, $before)),
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
    #[OA\Get(
        path: '/api/v2/statistics/daily',
        summary: 'Daily transaction counts with filters',
        description: 'Returns per-day transaction counts split by income/expense. Supports the same rich filter set as the ledger endpoint.',
        security: [['bearerAuth' => []]],
        tags: ['Statistics'],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: 1 year ago', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: today', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['expense', 'income'])),
            new OA\Parameter(name: 'accounts[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'categories[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'excludedCategories[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'currencies[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'isDraft', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['0', '1', 'true', 'false'])),
            new OA\Parameter(name: 'note', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'amount[gte]', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'amount[lte]', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'affectingProfit', in: 'query', required: false, description: 'Only profit-affecting transactions', schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daily stats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'day', type: 'string', format: 'date'),
                        new OA\Property(property: 'expense', type: 'integer'),
                        new OA\Property(property: 'income', type: 'integer'),
                    ])),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\StatisticsControllerTest
     *
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
        $amount = $request->query->all('amount');
        $amountGte = isset($amount['gte']) && is_numeric($amount['gte']) ? (float) $amount['gte'] : null;
        $amountLte = isset($amount['lte']) && is_numeric($amount['lte']) ? (float) $amount['lte'] : null;

        $note = (\is_string($note) && '' !== trim($note)) ? trim($note) : null;

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
