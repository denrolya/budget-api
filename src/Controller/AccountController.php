<?php

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Attribute\MapCarbonInterval;
use App\Entity\Account;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/account', name: 'api_v2_account_')]
class AccountController extends AbstractFOSRestController
{
    #[Rest\QueryParam(name: 'after', description: 'After date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'interval', description: 'ISO 8601 duration (P1D, P1W, P1M)', default: 'P1W', nullable: true)]
    #[Rest\View]
    /**
     * @see \App\Tests\Controller\AccountStatsTest
     * @tested testBalanceHistory_returnsCorrectShape
     * @tested testBalanceHistory_weeklyInterval
     * @tested testBalanceHistory_emptyRange_returnsEmptyData
     * @tested testBalanceHistory_withoutAuth_returns401
     */
    #[Route('/{id<\d+>}/balance-history', name: 'balance_history', methods: ['get'])]
    public function balanceHistory(
        Account $account,
        TransactionRepository $transactionRepo,
        #[MapCarbonDate(format: 'Y-m-d', default: '-6 months')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'now')] CarbonImmutable $before,
        #[MapCarbonInterval(default: 'P1W')] CarbonInterval $interval,
    ): View {
        $after  = $after->startOfDay();
        $before = $before->endOfDay();

        // Generate chart date points using the requested interval.
        $dates = CarbonPeriod::create($after, $interval, $before)->toArray();

        // Fetch all transactions for this account from $after onward (no upper bound),
        // so transactions between $before and now are also included for correct backward walk.
        $transactions = $transactionRepo->getList(
            after: $after,
            before: null,
            accounts: [$account],
            affectingProfitOnly: false,
            orderField: 'executedAt',
            order: 'DESC',
        );

        // Walk backward from current balance, undoing each transaction
        // that happened after the current date point.
        $runningBalance = $account->getBalance();
        $txIndex = 0;
        $txCount = count($transactions);
        $points = [];

        foreach (array_reverse($dates) as $date) {
            while ($txIndex < $txCount && $transactions[$txIndex]->getExecutedAt()->greaterThan($date)) {
                $tx = $transactions[$txIndex++];
                $runningBalance += $tx->isExpense() ? $tx->getAmount() : -$tx->getAmount();
            }
            $points[] = ['timestamp' => $date->unix(), 'balance' => round($runningBalance, 2)];
        }

        return $this->view([
            'currency' => $account->getCurrency(),
            'data'     => array_reverse($points),
        ]);
    }

    #[Rest\QueryParam(name: 'after', description: 'After date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date (Y-m-d)', nullable: true)]
    #[Rest\View]
    /**
     * @see \App\Tests\Controller\AccountStatsTest
     * @tested testDailyStats_returnsCorrectShape
     * @tested testDailyStats_emptyRange_returnsEmptyData
     * @tested testDailyStats_uahAccount_returnsData
     * @tested testDailyStats_withoutAuth_returns401
     */
    #[Route('/{id<\d+>}/daily-stats', name: 'daily_stats', methods: ['get'])]
    public function dailyStats(
        Account $account,
        TransactionRepository $transactionRepo,
        #[MapCarbonDate(format: 'Y-m-d', default: '-1 year')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'now')] CarbonImmutable $before,
    ): View {
        return $this->view([
            'data' => $transactionRepo->countByDay(
                after: $after->startOfDay(),
                before: $before->endOfDay(),
                accounts: [$account->getId()],
            ),
        ]);
    }
}
