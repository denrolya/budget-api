<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\MapCarbonDate;
use App\Entity\Transaction;
use App\Entity\Transfer;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Repository\TransferRepository;
use Carbon\CarbonImmutable;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/ledgers', name: 'api_v2_ledgers_')]
#[OA\Tag(name: 'Ledger')]
final class LedgerController extends AbstractFOSRestController
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly TransferRepository $transferRepository,
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

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
    #[Rest\QueryParam(name: 'perPage', requirements: '^(20|50|100|[1-9][0-9]*)$', default: TransactionRepository::PER_PAGE)]
    #[Rest\QueryParam(name: 'page', requirements: '^[1-9][0-9]*$', default: 1)]
    #[Rest\View(serializerGroups: ['transaction:collection:read', 'transfer:collection:read'])]
    #[Route('', name: 'collection_read', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v2/ledgers',
        summary: 'Unified ledger',
        description: 'Returns a merged, paginated list of transactions and transfers sorted by executedAt DESC. Supports filtering by type, account, category, debt, note, draft status, currency, and amount range. Transfers are excluded when isDraft, currency, category, or debt filters are active (transfers have no such fields).',
        tags: ['Ledger'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'after', in: 'query', required: false, description: 'Start date (Y-m-d), default: first day of current month', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'before', in: 'query', required: false, description: 'End date (Y-m-d), default: last day of current month', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'expense | income | transfer', schema: new OA\Schema(type: 'string', enum: ['expense', 'income', 'transfer'])),
            new OA\Parameter(name: 'account[]', in: 'query', required: false, description: 'Account IDs', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'category[]', in: 'query', required: false, description: 'Category IDs', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'debt[]', in: 'query', required: false, description: 'Debt IDs', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'note', in: 'query', required: false, description: 'Substring search in note', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'isDraft', in: 'query', required: false, description: '1 = only drafts, 0 = only non-drafts', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'withNestedCategories', in: 'query', required: false, description: '1 = expand category filter to descendants', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'currencies[]', in: 'query', required: false, description: 'Currency codes', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'amount[gte]', in: 'query', required: false, description: 'Minimum amount', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'amount[lte]', in: 'query', required: false, description: 'Maximum amount', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'perPage', in: 'query', required: false, description: 'Items per page', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number (1-based)', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated ledger',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'list', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'count', type: 'integer', example: 142),
                    new OA\Property(property: 'totalValue', type: 'number', format: 'float', example: -3421.50),
                ]),
            ),
            new OA\Response(response: 400, description: 'Invalid amount range (gte > lte)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * Unified ledger endpoint: returns non-transfer transactions merged with transfers,
     * sorted by executedAt DESC, paginated.
     *
     * type=expense|income  → only the respective transaction type, no transfers
     * type=transfer        → only transfers, no transactions
     * type absent          → all (non-transfer transactions + transfers)
     *
     * @see \App\Tests\Controller\LedgerControllerTest
     *
     * @tested testUnauthenticatedRequestIsRejected
     * @tested testAuthenticatedUserCanAccessLedger
     * @tested testTransferTransactionsAreExcludedAndTransfersIncluded
     * @tested testTypeExpenseFilterReturnsOnlyExpenses
     * @tested testTypeIncomeFilterReturnsOnlyIncomes
     * @tested testTypeTransferFilterReturnsOnlyTransfers
     * @tested testPagination
     * @tested testItemsAreSortedDescendingByExecutedAt
     * @tested testAccountFilter
     * @tested testCategoryFilter
     * @tested testDebtFilter
     * @tested testNoteFilterMatchesTransactionsAndTransfers
     * @tested testIsDraftFilter
     * @tested testTotalValueExcludesTransfers
     * @tested testWithNestedCategoriesFilter
     * @tested testCurrenciesFilter
     * @tested testAmountRangeFilter
     * @tested testAmountRangeFilterAppliesToTransfers
     * @tested testAmountRangeFilterWithoutTypeIncludesMatchingTransfers
     * @tested testAmountRangeFilterBoundaryValues
     * @tested testTransferAmountFilterGteOnly
     * @tested testTransferAmountFilterLteOnly
     * @tested testTransferAmountFilterNoMatchReturnsEmpty
     * @tested testInvalidAmountRangeReturnsError
     * @tested testWithNestedCategoriesNonExistentIdReturnsEmpty
     * @tested testTotalValueCoversAllPagesNotJustCurrentPage
     * @tested testCombinedFilters_accountAndCategoryAndDateRange
     * @tested testEmptyDateRange_returnsZeroResults
     * @tested testNonExistentCategoryIdWithoutExpansionReturnsEmpty
     */
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
        int $perPage = TransactionRepository::PER_PAGE,
        int $page = 1,
    ): View {
        $note = (\is_string($note) && '' !== trim($note)) ? trim($note) : null;
        $isDraftBool = match ($isDraft) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $amount = $request->query->all('amount');
        $amountGte = isset($amount['gte']) && is_numeric($amount['gte']) ? (float) $amount['gte'] : null;
        $amountLte = isset($amount['lte']) && is_numeric($amount['lte']) ? (float) $amount['lte'] : null;

        if (null !== $amountGte && null !== $amountLte && $amountGte > $amountLte) {
            throw new InvalidArgumentException('amount[gte] cannot be greater than amount[lte]');
        }

        // ── Normalize account/category/debt to int arrays ──────────────────
        $accountIds = $this->toIntArray($account);
        $categoryIds = $this->toIntArray($category);
        $debtIds = $this->toIntArray($debt);

        $hadCategoryFilter = [] !== $categoryIds;

        $includeTransactions = (null === $type || Transaction::EXPENSE === $type || Transaction::INCOME === $type);

        // ── Expand categories to include descendants when requested ─────────
        // Only needed for the transaction query — skip when transactions are excluded entirely.
        if ($includeTransactions && '1' === $withNestedCategories && [] !== $categoryIds) {
            $expanded = $this->categoryRepository->getCategoriesWithDescendantsByType($categoryIds, $type);
            $categoryIds = array_map(static fn ($c) => $c->getId(), $expanded);
        }
        // Transfers have no isDraft/currency/category/debt fields — exclude them when those filters are active
        $includeTransfers = (null === $type || 'transfer' === $type)
            && null === $isDraftBool
            && (null === $currencies || [] === $currencies)
            && [] === $debtIds
            && !$hadCategoryFilter;

        // ── Fetch data ──────────────────────────────────────────────────────
        $transactions = $includeTransactions ? $this->transactionRepository->getListForLedger(
            after: $after,
            before: $before,
            type: 'transfer' === $type ? null : $type,
            accounts: [] !== $accountIds ? $accountIds : null,
            // [0] is an impossible sentinel: categories were requested but expansion found nothing,
            // so the query must return zero results rather than removing the filter entirely.
            categories: [] !== $categoryIds ? $categoryIds : ($hadCategoryFilter ? [0] : null),
            debts: [] !== $debtIds ? $debtIds : null,
            note: $note,
            isDraft: $isDraftBool,
            amountGte: $amountGte,
            amountLte: $amountLte,
            currencies: [] !== $currencies ? $currencies : null,
        ) : [];

        $transfers = $includeTransfers ? $this->transferRepository->getListForLedger(
            after: $after,
            before: $before,
            accounts: [] !== $accountIds ? $accountIds : null,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
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

        // ── Count (authoritative totals from DB, independent of the fetch limit) ─
        $transactionCount = $includeTransactions ? $this->transactionRepository->countForLedger(
            after: $after,
            before: $before,
            type: 'transfer' === $type ? null : $type,
            accounts: [] !== $accountIds ? $accountIds : null,
            categories: [] !== $categoryIds ? $categoryIds : ($hadCategoryFilter ? [0] : null),
            debts: [] !== $debtIds ? $debtIds : null,
            note: $note,
            isDraft: $isDraftBool,
            amountGte: $amountGte,
            amountLte: $amountLte,
            currencies: [] !== $currencies ? $currencies : null,
        ) : 0;

        $transferCount = $includeTransfers ? $this->transferRepository->countForLedger(
            after: $after,
            before: $before,
            accounts: [] !== $accountIds ? $accountIds : null,
            note: $note,
            amountGte: $amountGte,
            amountLte: $amountLte,
        ) : 0;

        // ── Paginate ────────────────────────────────────────────────────────
        $total = $transactionCount + $transferCount;
        $offset = ($page - 1) * $perPage;
        $pageItems = \array_slice($merged, $offset, $perPage);

        // ── Total value (transactions only, net) ────────────────────────────
        /** @var User|null $user */
        $user = $this->getUser();
        $baseCurrency = $user?->getBaseCurrency() ?? 'EUR';
        $totalValue = $includeTransactions ? $this->transactionRepository->sumConverted(
            baseCurrency: $baseCurrency,
            after: $after,
            before: $before,
            type: 'transfer' === $type ? null : $type,
            accounts: [] !== $accountIds ? $accountIds : null,
            categories: [] !== $categoryIds ? $categoryIds : ($hadCategoryFilter ? [0] : null),
            debts: [] !== $debtIds ? $debtIds : null,
            note: $note,
            isDraft: $isDraftBool,
            amountGte: $amountGte,
            amountLte: $amountLte,
            currencies: [] !== $currencies ? $currencies : null,
            excludeTransferTransactions: true,
        ) : 0.0;

        return $this->view([
            'list' => $pageItems,
            'count' => $total,
            'totalValue' => round($totalValue, 2),
        ]);
    }

    /**
     * Coerce a nullable array from query params to a list of positive integers.
     *
     * @return array<int>
     */
    private function toIntArray(mixed $raw): array
    {
        $flatValues = [];

        if (\is_array($raw)) {
            array_walk_recursive($raw, static function (mixed $value) use (&$flatValues): void {
                $flatValues[] = $value;
            });
        } elseif (null !== $raw) {
            $flatValues[] = $raw;
        }

        $result = [];
        foreach ($flatValues as $v) {
            if (!is_numeric($v)) {
                continue;
            }
            $int = (int) $v;
            if ($int > 0) {
                $result[$int] = $int;
            }
        }

        return array_values($result);
    }
}
