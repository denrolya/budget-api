<?php

declare(strict_types=1);

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
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/accounts', name: 'api_v2_accounts_')]
#[OA\Tag(name: 'Account')]
class AccountController extends AbstractFOSRestController
{
    #[Rest\QueryParam(name: 'after', description: 'After date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'interval', description: 'ISO 8601 duration (P1D, P1W, P1M)', default: 'P1W', nullable: true)]
    #[Rest\View]
    #[Route('/{id<\d+>}/balance-history', name: 'balance_history', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/accounts/{id}/balance-history',
        summary: 'Account balance history over time',
        description: 'Returns balance data points for the given account, walking backwards from the current balance to reconstruct the historical balance at each interval boundary.',
        security: [['bearerAuth' => []]],
        tags: ['Account'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: 6 months ago', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: today', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'interval', in: 'query', required: false, description: 'ISO 8601 interval (P1D, P1W, P1M), default: P1W', schema: new OA\Schema(type: 'string', default: 'P1W')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Balance history',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'currency', type: 'string', example: 'USD'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'timestamp', type: 'integer', example: 1700000000),
                        new OA\Property(property: 'balance', type: 'number', format: 'float', example: 1234.56),
                    ])),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Account not found'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\AccountStatsTest
     *
     * @tested testBalanceHistory_returnsCorrectShape
     * @tested testBalanceHistory_weeklyInterval
     * @tested testBalanceHistory_emptyRange_returnsEmptyData
     * @tested testBalanceHistory_withoutAuth_returns401
     */
    public function balanceHistory(
        Account $account,
        TransactionRepository $transactionRepo,
        #[MapCarbonDate(format: 'Y-m-d', default: '-6 months')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'now')] CarbonImmutable $before,
        #[MapCarbonInterval(default: 'P1W')] CarbonInterval $interval,
    ): View {
        $after = $after->startOfDay();
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
        $txCount = \count($transactions);
        $points = [];

        foreach (array_reverse($dates) as $date) {
            while ($txIndex < $txCount && ($executedAt = $transactions[$txIndex]->getExecutedAt()) !== null && $executedAt->greaterThan($date)) {
                $tx = $transactions[$txIndex++];
                $runningBalance += $tx->isExpense() ? $tx->getAmount() : -$tx->getAmount();
            }
            $points[] = ['timestamp' => $date->unix(), 'balance' => round($runningBalance, 2)];
        }

        return $this->view([
            'currency' => $account->getCurrency(),
            'data' => array_reverse($points),
        ]);
    }

    #[Rest\QueryParam(name: 'after', description: 'After date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'Before date (Y-m-d)', nullable: true)]
    #[Rest\View]
    #[Route('/{id<\d+>}/daily-stats', name: 'daily_stats', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/accounts/{id}/daily-stats',
        summary: 'Daily transaction counts per account',
        description: 'Returns per-day income and expense transaction counts for the given account within the date range.',
        security: [['bearerAuth' => []]],
        tags: ['Account'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: 1 year ago', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: today', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daily stats',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'day', type: 'string', format: 'date', example: '2024-01-15'),
                        new OA\Property(property: 'expense', type: 'integer', example: 3),
                        new OA\Property(property: 'income', type: 'integer', example: 1),
                    ])),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Account not found'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\AccountStatsTest
     *
     * @tested testDailyStats_returnsCorrectShape
     * @tested testDailyStats_emptyRange_returnsEmptyData
     * @tested testDailyStats_uahAccount_returnsData
     * @tested testDailyStats_withoutAuth_returns401
     */
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
