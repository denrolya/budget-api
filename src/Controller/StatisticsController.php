<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Service\AssetsManager;
use App\Service\StatisticsManager;
use App\Traits\SoftDeletableTogglerController;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Transaction;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use function PHPUnit\Framework\assertDirectoryDoesNotExist;

#[Route('/api/v2/statistics', name: 'api_v2_statistics_')]
class StatisticsController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'interval', default: '1 month', nullable: true, allowBlank: false)]
    #[ParamConverter('interval', CarbonInterval::class, options: ['default' => '1 month'])]
    #[Route('/money-flow', name: 'money_flow', methods: ['get'])]
    public function moneyFlow(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, CarbonInterval $interval): View
    {
        $transactions = $doctrine->getRepository(Transaction::class)->findWithinPeriod($after, $before);

        return $this->view(
            $statisticsManager->generateMoneyFlowStatistics($transactions, new CarbonPeriod($after, $interval, $before))
        );
    }

    #[Rest\QueryParam(name: 'after', description: 'After date', nullable: true)]
    #[ParamConverter('after', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'first day of this month'])]
    #[Rest\QueryParam(name: 'before', description: 'Before date', nullable: true)]
    #[ParamConverter('before', class: CarbonImmutable::class, options: ['format' => 'Y-m-d', 'default' => 'last day of this month'])]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income)', default: null, nullable: true, allowBlank: false)]
    #[Route('/tree', name: 'tree', methods: ['get'])]
    public function categoryTree(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $this->disableSoftDeletable();
        $transactions = $doctrine->getRepository($type === Transaction::EXPENSE
            ? Expense::class
            : Income::class
        )->findWithinPeriod($after, $before);

        // TODO: Debug memory usage. Has to be JMS Serializer
        return $this->view(
            $statisticsManager->generateCategoryTreeWithValues(
                null,
                $transactions, $type === Transaction::EXPENSE
                ? ExpenseCategory::class
                : IncomeCategory::class
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
                $repo->findWithinPeriod($after, $before)
            )
        );
    }
}
