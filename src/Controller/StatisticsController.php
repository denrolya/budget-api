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

#[Route('/api/v2/statistics', name: 'api_v2_statistics_')]
class StatisticsController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    /**
     * @Rest\QueryParam(name="after", nullable=true, description="After date")
     * @ParamConverter("after", options={"format": "Y-m-d", "default"="first day of this month"})
     * @Rest\QueryParam(name="before", nullable=true, description="Before date")
     * @ParamConverter("before", options={"format": "Y-m-d", "default"="first day of next month"})
     * @Rest\QueryParam(name="interval", nullable=true, allowBlank=false, description="Interval to group by")
     * @ParamConverter("interval", options={"default"="1 month"})
     */
    #[Route('/money-flow', name: 'money_flow', methods: ['get'])]
    public function moneyFlow(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, CarbonInterval $interval): View
    {
        $transactions = $doctrine->getRepository(Transaction::class)->findWithinPeriod($after, $before);

        return $this->view(
            $statisticsManager->generateMoneyFlowStatistics($transactions, new CarbonPeriod($after, $interval, $before))
        );
    }

    /**
     * TODO: Debug memory usage. Has to be JMS Serializer
     *
     * @Rest\QueryParam(name="after", nullable=true, description="After date")
     * @ParamConverter("after", options={"format": "Y-m-d", "default"="first day of this month"})
     * @Rest\QueryParam(name="before", nullable=true, description="Before date")
     * @ParamConverter("before", options={"format": "Y-m-d", "default"="first day of next month"})
     * @Rest\QueryParam(name="type", requirements="(expense|income)", nullable=true, allowBlank=false, default="expense",
     *     description="Type of transactions to calculate")
     */
    #[Route('/tree', name: 'tree', methods: ['get'])]
    public function categoryTree(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, CarbonImmutable $after, CarbonImmutable $before, string $type): View
    {
        $this->disableSoftDeletable();
        $transactions = $doctrine->getRepository($type === Transaction::EXPENSE
            ? Expense::class
            : Income::class
        )->findWithinPeriod($after, $before);

        return $this->view(
            $statisticsManager->generateCategoryTreeWithValues(
                null,
                $transactions, $type === Transaction::EXPENSE
                ? ExpenseCategory::class
                : IncomeCategory::class
            )
        );
    }
}
