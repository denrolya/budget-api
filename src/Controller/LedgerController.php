<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use App\Pagination\Paginator;
use App\Repository\CategoryRepository;
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
        private readonly CategoryRepository $categoryRepository,
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
    #[Rest\QueryParam(name: 'isDraft', requirements: '(0|1)', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'withNestedCategories', requirements: '^(0|1)$', default: null, description: 'Expand category filter to include descendants', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'currencies', description: 'Filter by account currency codes', nullable: true, allowBlank: false)]
    #[Rest\QueryParam(name: 'amount[gte]', description: 'Amount >= value (numeric)', nullable: true, allowBlank: true)]
    #[Rest\QueryParam(name: 'amount[lte]', description: 'Amount <= value (numeric)', nullable: true, allowBlank: true)]
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
        ?string $isDraft = null,
        ?string $withNestedCategories = null,
        ?array $currencies = null,
        int $perPage = Paginator::PER_PAGE,
        int $page = 1,
    ): View {
        $note = (is_string($note) && trim($note) !== '') ? trim($note) : null;
        $isDraftBool = match ($isDraft) {
            '1'     => true,
            '0'     => false,
            default => null,
        };

        $amount    = $request->query->all('amount');
        $amountGte = isset($amount['gte']) && is_numeric($amount['gte']) ? (float) $amount['gte'] : null;
        $amountLte = isset($amount['lte']) && is_numeric($amount['lte']) ? (float) $amount['lte'] : null;

        if ($amountGte !== null && $amountLte !== null && $amountGte > $amountLte) {
            throw new \InvalidArgumentException('amount[gte] cannot be greater than amount[lte]');
        }

        // ── Normalize account/category/debt to int arrays ──────────────────
        $accountIds  = $this->toIntArray($account);
        $categoryIds = $this->toIntArray($category);
        $debtIds     = $this->toIntArray($debt);

        // ── Expand categories to include descendants when requested ─────────
        if ($withNestedCategories === '1' && $categoryIds !== []) {
            $txType = ($type === 'transfer') ? null : $type;
            $expanded = $this->categoryRepository->getCategoriesWithDescendantsByType($categoryIds, $txType);
            $categoryIds = array_map(static fn($c) => $c->getId(), $expanded);
        }

        $includeTransactions = ($type === null || $type === Transaction::EXPENSE || $type === Transaction::INCOME);
        // Transfers have no isDraft/currency/amount fields — exclude them when those filters are active
        $includeTransfers    = ($type === null || $type === 'transfer')
            && $isDraftBool === null
            && $amountGte === null
            && $amountLte === null
            && empty($currencies);

        // ── Fetch data ──────────────────────────────────────────────────────
        $transactions = $includeTransactions ? $this->transactionRepository->getListForLedger(
            after: $after,
            before: $before,
            type: $type === 'transfer' ? null : $type,
            accounts: $accountIds ?: null,
            categories: $categoryIds ?: null,
            debts: $debtIds ?: null,
            note: $note,
            isDraft: $isDraftBool,
            amountGte: $amountGte,
            amountLte: $amountLte,
            currencies: $currencies ?: null,
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
        $flatValues = [];

        if (is_array($raw)) {
            array_walk_recursive($raw, static function (mixed $value) use (&$flatValues): void {
                $flatValues[] = $value;
            });
        } elseif ($raw !== null) {
            $flatValues[] = $raw;
        }

        $result = [];
        foreach ($flatValues as $v) {
            $int = (int) $v;
            if ($int > 0) {
                $result[$int] = $int;
            }
        }

        return array_values($result);
    }
}
