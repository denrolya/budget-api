<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\TransactionInterface;
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
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/statistics', name: 'api_v2_statistics_')]
class StatisticsController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'interval', default: false, nullable: false, allowBlank: true)]
    #[ParamConverter('interval', CarbonInterval::class)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/value-by-period', name: 'value_by_period', methods: ['get'])]
    public function value(EntityManagerInterface $em, CategoryRepository $categoryRepo, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, ?CarbonInterval $interval, ?string $type, array $accounts, array $categories): View
    {
        return $this->view(
            $statisticsManager->calculateTransactionsValueByPeriod(
                period: CarbonPeriod:: create($after, $interval, $before)->excludeEndDate(),
                type: $type,
                categories: !empty($categories) ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type) : $categories,
                accounts: $accounts
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: false, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/tree', name: 'category_tree', methods: ['get'])]
    public function categoryTree(TransactionRepository $transactionRepo, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            type: $type,
        );

        return $this->view(
            $statisticsManager->generateCategoryTreeWithValues(
                transactions: $transactions,
                type: $type
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'interval', default: '1 month', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'categories', description: 'Filter by categories', nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/timeline', name: 'category_timeline', methods: ['get'])]
    public function categoryTimeline(TransactionRepository $transactionRepo, CategoryRepository $categoryRepo, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, CarbonInterval $interval, ?array $categories): View
    {
        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            categories: !empty($categories) ? $categoryRepo->getCategoriesWithDescendantsByType($categories) : $categories,
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
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this year'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this year'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Rest\View(serializerGroups: ['account:collection:read'])]
    #[Route('/account-distribution', name: 'account_distribution', methods: ['get'])]
    public function accountDistribution(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $this->disableSoftDeletable();
        $repo = $doctrine->getRepository(($type === 'expense') ? Expense::class : Income::class);

        return $this->view(
            $statisticsManager->generateAccountDistributionStatistics(
                $repo->getList(after: $after, before: $before, type: $type)
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this year'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this year'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: TransactionInterface::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/by-weekdays', name: 'by_weekdays', methods: ['get'])]
    public function transactionsValueByWeekdays(TransactionRepository $transactionRepo, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            type: $type,
        );

        return $this->view(
            $statisticsManager->generateTransactionsValueByCategoriesByWeekdays($transactions)
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this year'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this year'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: TransactionInterface::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/top-value-category', name: 'top_value_category', methods: ['get'])]
    public function topValueCategory(TransactionRepository $transactionRepo, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $transactions = $transactionRepo->getList(
            after: $after,
            before: $before,
            type: $type
        );

        return $this->view(
            $statisticsManager->generateTopValueCategoryStatistics($transactions)
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'interval', default: null, nullable: true, allowBlank: true)]
    #[ParamConverter('interval', CarbonInterval::class)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/avg', name: 'average', methods: ['get'])]
    public function average(TransactionRepository $transactionRepo, CategoryRepository $categoryRepo, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, ?CarbonInterval $interval, ?string $type, array $accounts, array $categories): View
    {
        $transactions = $transactionRepo
            ->getList(
                after: $after,
                before: $before,
                type: $type,
                categories: !empty($categories) ? $categoryRepo->getCategoriesWithDescendantsByType($categories, $type) : $categories,
                accounts: $accounts,
            );

        return $this->view(
            $statisticsManager->averageByPeriod(
                $transactions,
                new CarbonPeriod($after, $interval, $before)
            )
        );
    }
}
