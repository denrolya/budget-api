<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\TransactionInterface;
use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use App\Traits\SoftDeletableTogglerController;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Transaction;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;

#[Route('/api/v2/statistics', name: 'api_v2_statistics_')]
class StatisticsController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'interval', default: null, nullable: true, allowBlank: true)]
    #[ParamConverter('interval', CarbonInterval::class)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/value-by-period', name: 'value_by_period', methods: ['get'])]
    public function value(ManagerRegistry $doctrine, AssetsManager $assetsManager, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, ?CarbonInterval $interval, ?string $type, array $accounts, array $categories): View
    {
        $transactions = $doctrine
            ->getRepository(Transaction::class)
            ->getList(
                $after,
                $before,
                $type,
                !empty($categories) ? $assetsManager->getTypedCategoriesWithChildren($type, $categories) : $categories,
                $accounts,
                [],
                true,
                false
            );

        return $this->view(
            $statisticsManager->calculateTransactionsValueByPeriod(
                $transactions,
                new CarbonPeriod($after, $interval, $before)
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'accounts', default: [], description: 'Filter by accounts', nullable: false, allowBlank: false)]
    #[Rest\QueryParam(name: 'categories', default: [], description: 'Filter by categories', nullable: false, allowBlank: false)]
    #[Route('/sum', name: 'sum', methods: ['get'])]
    public function sum(ManagerRegistry $doctrine, AssetsManager $assetsManager, CarbonImmutable $after, CarbonImmutable $before, ?CarbonInterval $interval, ?string $type, array $accounts, array $categories): View
    {
        // TODO: If no interval - provide sum
        // TODO: If interval provided - sum by interval; return array of values with dates
        $transactions = $doctrine->getRepository(Transaction::class)->getList(
            $after,
            $before,
            $type,
            !empty($categories) ? $assetsManager->getTypedCategoriesWithChildren($type, $categories) : $categories,
            $accounts,
            [],
            true,
            false
        );

        $result = isset($type)
            ? $assetsManager->sumTransactions($transactions)
            : $assetsManager->sumMixedTransactions($transactions);

        return $this->view($result);
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: true)]
    #[Rest\View(serializerGroups: ['category:tree:read'])]
    #[Route('/category/tree', name: 'category_tree', methods: ['get'])]
    public function categoryTree(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $transactions = $doctrine->getRepository(Transaction::class)->getList($after, $before, $type, [], [], [], true, false);

        return $this->view(
            $statisticsManager->generateCategoryTreeWithValues(
                [],
                $transactions,
                $type
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
    public function categoryTimeline(ManagerRegistry $doctrine, AssetsManager $assetsManager, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, CarbonInterval $interval, ?array $categories): View
    {
        $transactions = $doctrine->getRepository(Transaction::class)->getList(
            $after,
            $before,
            null,
            !empty($categories) ? $assetsManager->getTypedCategoriesWithChildren(null, $categories) : $categories,
            [],
            [],
            true,
            false
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
                $repo->getList($after, $before, $type, [], [], [], true, false)
            )
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this year'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this year'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: TransactionInterface::EXPENSE, nullable: true, allowBlank: false)]
    #[Route('/by-weekdays', name: 'by_weekdays', methods: ['get'])]
    public function transactionsValueByWeekdays(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $transactions = $doctrine->getRepository(Transaction::class)->getList($after, $before, $type, [], [], [], true, false, 'executedAt', 'ASC');

        return $this->view(
            $statisticsManager->generateTransactionsValueByCategoriesByWeekdays($transactions)
        );
    }
}
