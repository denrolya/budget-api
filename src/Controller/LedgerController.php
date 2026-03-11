<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use App\Pagination\Paginator;
use App\Repository\TransactionRepository;
use App\Repository\TransferRepository;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/ledger', name: 'api_v2_ledger_')]
final class LedgerController extends AbstractFOSRestController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly TransferRepository $transferRepository,
    ) {
    }

    /**
     * Unified ledger endpoint: returns non-transfer transactions merged with transfers,
     * sorted by executedAt DESC, paginated.
     *
     * type=expense|income  → only the respective transaction type, no transfers
     * type=transfer        → only transfers, no transactions
     * type absent          → all (non-transfer transactions + transfers)
     */
    #[Rest\QueryParam(name: 'after', description: 'Start date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'before', description: 'End date (Y-m-d)', nullable: true)]
    #[Rest\QueryParam(name: 'type', requirements: '(expense|income|transfer)', default: null, nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'account', description: 'Filter by account IDs', nullable: true)]
    #[Rest\QueryParam(name: 'category', description: 'Filter by category IDs', nullable: true)]
    #[Rest\QueryParam(name: 'debt', description: 'Filter by debt IDs', nullable: true)]
    #[Rest\QueryParam(name: 'note', description: 'Search substring in note', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'perPage', requirements: '^(20|50|100|[1-9][0-9]*)$', default: Paginator::PER_PAGE)]
    #[Rest\QueryParam(name: 'page', requirements: '^[1-9][0-9]*$', default: 1)]
    #[Rest\View(serializerGroups: ['transaction:collection:read', 'transfer:collection:read'])]
    #[Route('', name: 'collection_read', methods: ['GET'])]
    public function list(
        Request $request,
        #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
        #[MapCarbonDate(format: 'Y-m-d', default: 'last day of this month')] CarbonImmutable $before,
        ?string $type = null,
        ?array $account = null,
        ?array $category = null,
        ?array $debt = null,
        ?string $note = null,
        int $perPage = Paginator::PER_PAGE,
        int $page = 1,
    ): View {
        $note = (is_string($note) && trim($note) !== '') ? trim($note) : null;

        // ── Normalize account/category/debt to int arrays ──────────────────
        $accountIds  = $this->toIntArray($account);
        $categoryIds = $this->toIntArray($category);
        $debtIds     = $this->toIntArray($debt);

        $includeTransactions = ($type === null || $type === Transaction::EXPENSE || $type === Transaction::INCOME);
        $includeTransfers    = ($type === null || $type === 'transfer');

        // ── Fetch data ──────────────────────────────────────────────────────
        $transactions = $includeTransactions ? $this->transactionRepository->getListForLedger(
            after: $after,
            before: $before,
            type: $type === 'transfer' ? null : $type,
            accounts: $accountIds ?: null,
            categories: $categoryIds ?: null,
            debts: $debtIds ?: null,
            note: $note,
        ) : [];

        $transfers = $includeTransfers ? $this->transferRepository->getListForLedger(
            after: $after,
            before: $before,
            accounts: $accountIds ?: null,
            note: $note,
        ) : [];

        // ── Merge + sort descending by executedAt ───────────────────────────
        /** @var array<Transaction|Transfer> $merged */
        $merged = array_merge($transactions, $transfers);

        usort($merged, static function (Transaction|Transfer $a, Transaction|Transfer $b): int {
            $timeA = ($a->getExecutedAt()?->getTimestamp() ?? 0);
            $timeB = ($b->getExecutedAt()?->getTimestamp() ?? 0);

            if ($timeA !== $timeB) {
                return $timeB - $timeA; // desc
            }

            // Stable secondary sort: transfers after transactions on the same second
            $aIsTransfer = $a instanceof Transfer;
            $bIsTransfer = $b instanceof Transfer;
            if ($aIsTransfer !== $bIsTransfer) {
                return $aIsTransfer ? 1 : -1;
            }

            return $b->getId() - $a->getId(); // desc by id
        });

        // ── Paginate ────────────────────────────────────────────────────────
        $total  = count($merged);
        $offset = ($page - 1) * $perPage;
        $page_items = array_slice($merged, $offset, $perPage);

        // ── Total value (transactions only, net) ────────────────────────────
        /** @var User|null $user */
        $user         = $this->getUser();
        $baseCurrency = $user?->getBaseCurrency() ?? 'EUR';
        $totalValue   = $this->calculateTotalValue($transactions, $baseCurrency);

        return $this->view([
            'list'       => $page_items,
            'count'      => $total,
            'totalValue' => round($totalValue, 2),
        ]);
    }

    /**
     * @param array<Transaction> $transactions
     */
    private function calculateTotalValue(array $transactions, string $baseCurrency): float
    {
        $value = 0.0;
        foreach ($transactions as $tx) {
            $converted = $tx->getConvertedValue($baseCurrency) ?? 0.0;
            $value += $tx->isExpense() ? -$converted : $converted;
        }
        return $value;
    }

    /**
     * Coerce a nullable array from query params to a list of positive integers.
     *
     * @param mixed $raw
     * @return array<int>
     */
    private function toIntArray(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $v) {
            $int = (int) $v;
            if ($int > 0) {
                $result[] = $int;
            }
        }
        return $result;
    }
}
