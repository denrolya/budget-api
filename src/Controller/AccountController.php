<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use App\Service\StatisticsManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/account', name: 'api_v2_account_')]
class AccountController extends AbstractFOSRestController
{

    #[Rest\View(serializerGroups: ['account:collection:read'])]
    #[Route('', name: 'collection_read', methods: ['get'])]
    public function collection(ManagerRegistry $doctrine): View
    {
        return $this->view(
            $doctrine->getRepository(Account::class)->findAll()
        );
    }

    /**
     * TODO: Check efficiency. Add frontend data display. Do we really need this and AccountItemProvider? which one are we consuming currently?
     */
    #[Rest\View(serializerGroups: ['account:item:read'])]
    #[Route('/{id<\d+>}', name: 'item_read', methods: ['get'])]
    public function item(ManagerRegistry $doctrine, StatisticsManager $statisticsManager, Account $account): View
    {
        /** @var TransactionRepository $transactionRepo */
        $transactionRepo = $doctrine->getRepository(Transaction::class);

        $accountTransactions = $transactionRepo->getList(
            categories: null,
            accounts: [$account]
        );

        $account->setTopExpenseCategories(
            $statisticsManager->generateCategoryTreeWithValues(
                transactions: array_filter($accountTransactions, static function (Transaction $transaction) {
                    return $transaction->isExpense();
                }),
                type: Transaction::EXPENSE,
            )
        );

        $account->setTopIncomeCategories(
            $statisticsManager->generateCategoryTreeWithValues(
                transactions: array_filter($accountTransactions, static function (Transaction $transaction) {
                    return $transaction->isIncome();
                }),
                type: Transaction::INCOME,
            )
        );

        return $this->view($account);
    }

    /**
     * TODO: Optimize for big intervals (e.g. 1 year) and accounts with many transactions (e.g. 1000+).
     * TODO: Describe query parameters as in TransactionsController
     * Returns account balance history as a time series.
     *
     * Algorithm: walk backward from account.balance (current) through transactions,
     * undoing each one to reconstruct balance at every requested date point.
     * Handles past-dated transactions correctly — no snapshots needed.
     *
     * Query params:
     *   after    – ISO date string, default: 6 months ago
     *   before   – ISO date string, default: today
     *   interval – ISO 8601 duration (P1D, P1W, P1M), default: P1W
     */
    #[Rest\View]
    #[Route('/{id<\d+>}/balance-history', name: 'balance_history', methods: ['get'])]
    public function balanceHistory(Account $account, Request $request, TransactionRepository $transactionRepo): View
    {
        $after  = CarbonImmutable::parse($request->query->get('after',  '-6 months'))->startOfDay();
        $before = CarbonImmutable::parse($request->query->get('before', 'now'))->endOfDay();
        $interval = $request->query->get('interval', 'P1W');

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

    /**
     * Returns transaction counts grouped by day for the given account.
     *
     * Query params:
     *   after  – ISO date string, default: 1 year ago
     *   before – ISO date string, default: today
     */
    #[Rest\View]
    #[Route('/{id<\d+>}/daily-stats', name: 'daily_stats', methods: ['get'])]
    public function dailyStats(Account $account, Request $request, TransactionRepository $transactionRepo): View
    {
        $after  = CarbonImmutable::parse($request->query->get('after',  '-1 year'))->startOfDay();
        $before = CarbonImmutable::parse($request->query->get('before', 'now'))->endOfDay();

        return $this->view([
            'data' => $transactionRepo->countByDay(
                after: $after,
                before: $before,
                accounts: [$account->getId()],
            ),
        ]);
    }
}
