<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Debt;
use App\Entity\Transaction;
use App\Entity\User;
use App\Pagination\Paginator;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Security\Core\Security;

class AssetsManager
{
    private EntityManagerInterface $em;

    private TransactionRepository $transactionRepo;

    private ExchangeRateSnapshotResolver $fxRateSnapshotResolver;

    public function __construct(
        EntityManagerInterface $em,
        ExchangeRateSnapshotResolver $snapshotResolver,
        private Security $security,
    ) {
        $this->em = $em;
        $this->transactionRepo = $this->em->getRepository(Transaction::class);
        $this->fxRateSnapshotResolver = $snapshotResolver;
    }

    /** @return array{list: mixed, totalValue: float, count: mixed} */
    public function generateTransactionPaginationData(
        ?CarbonInterface $after,
        ?CarbonInterface $before,
        ?string $type = null,
        ?array $categories = null,
        ?array $accounts = null,
        ?array $excludedCategories = null,
        bool $withChildCategories = true,
        ?bool $isDraft = null,
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $debts = null,
        ?array $currencies = null, // ok if repo treats null as no filter
        int $perPage = Paginator::PER_PAGE,
        int $page = 1,
        string $orderField = TransactionRepository::ORDER_FIELD,
        string $order = TransactionRepository::ORDER,
    ): array {
        // TODO: Optimize for efficiency. Consider adding this as argument to TransactionRepository::getList(and downwn to getBaseQueryBuilder) instead of as a filter, since it may be expensive to compute the descendant categories on every request, and it may be more efficient to handle this logic in a single place rather than in the filter which is applied after the query builder is created.
        $resolvedCategories = ($withChildCategories && $categories !== null && $categories !== [])
            ? $this->em->getRepository(Category::class)->getCategoriesWithDescendantsByType($categories, $type)
            : ($categories ?? []);

        $paginator = $this
            ->transactionRepo
            ->getPaginator(
                after: $after,
                before: $before,
                affectingProfitOnly: false,
                type: $type,
                categories: $resolvedCategories,
                accounts: $accounts ?? [],
                excludedCategories: $excludedCategories ?? [],
                isDraft: $isDraft,
                note: $note,
                amountGte: $amountGte,
                amountLte: $amountLte,
                debts: $debts ?? [],
                currencies: $currencies,
                limit: $perPage,
                page: $page,
                orderField: $orderField,
                order: $order,
            );

        return [
            'list' => $paginator->getResults(),
            'totalValue' => round(
                $this->transactionRepo->sumConverted(
                    baseCurrency: $this->getBaseCurrency(),
                    after: $after,
                    before: $before,
                    affectingProfitOnly: false,
                    type: $type,
                    categories: $resolvedCategories,
                    accounts: $accounts ?? [],
                    excludedCategories: $excludedCategories ?? [],
                    isDraft: $isDraft,
                    note: $note,
                    amountGte: $amountGte,
                    amountLte: $amountLte,
                    debts: $debts ?? [],
                    currencies: $currencies,
                ),
                2
            ),
            'count' => $paginator->getNumResults(),
        ];
    }

    public function calculateAverageWithinPeriod(array $transactions, CarbonPeriod $period): float
    {
        $byDate = [];

        $dates = $period->toArray();
        foreach ($dates as $after) {
            $before = next($dates);

            if ($before !== false) {
                $transactionsByDate = array_filter(
                    $transactions,
                    static function (Transaction $transaction) use ($after, $before) {
                        return $transaction->getExecutedAt()->startOfDay()->between($after, $before);
                    }
                );
                $byDate[$after->toDateString()] = count($transactionsByDate)
                    ? $this->sumTransactions($transactionsByDate) / count($transactionsByDate)
                    : 0;
            }
        }

        return array_sum(array_values($byDate)) / count($dates);
    }

    public function calculateAverageTransaction(array $transactions = []): float
    {
        $sum = array_reduce($transactions, static function (float $acc, Transaction $transaction) {
            return $acc + $transaction->getValue();
        }, 0);

        return $sum / (count($transactions) !== 0 ? count($transactions) : 1);
    }

    public function sumTransactionsFiltered(
        ?string $type,
        CarbonInterface $after,
        CarbonInterface $before,
        ?array $categories = [],
        ?array $accounts = [],
        ?array $excludedCategories = []
    ): float {
        return $this->transactionRepo->sumConverted(
            baseCurrency: $this->getBaseCurrency(),
            after: $after,
            before: $before,
            type: $type,
            categories: $categories,
            accounts: $accounts,
            excludedCategories: $excludedCategories,
        );
    }

    public function sumMixedTransactions(array $transactions, ?string $currency = null): float
    {
        return array_reduce($transactions, static function ($carry, Transaction $transaction) use ($currency) {
            return $carry + ($transaction->getConvertedValue($currency) * ($transaction->isExpense() ? -1 : 1));
        }, 0);
    }

    /**
     * Sum given transactions value in user's base currency
     */
    public function sumTransactions(array $transactions, ?string $currency = null): float
    {
        return array_reduce($transactions, static function ($carry, Transaction $transaction) use ($currency) {
            return $carry + $transaction->getConvertedValue($currency);
        }, 0);
    }

    /**
     * @throws NonUniqueResultException
     * @throws InvalidArgumentException
     */
    public function convert(Transaction|Debt $entity): array
    {
        // @phpstan-ignore-next-line
        $amount = (float)$entity->{'get'.ucfirst($entity->getValuableField())}();
        $fromCurrency = strtoupper($entity->getCurrency());

        $date = $this->resolveFxDate($entity);
        $snapshot = $this->fxRateSnapshotResolver->getClosestOrFetch($date);

        $result = [];
        foreach ($snapshot->getAvailableCurrencies() as $currency) {
            $value = $snapshot->convert($amount, $fromCurrency, $currency);
            if ($value !== null) {
                $result[$currency] = $value;
            }
        }

        return $result;
    }

    /**
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     */
    public function convertTo(Transaction|Debt $entity, ?string $toCurrency = null): float
    {
        // @phpstan-ignore-next-line
        $amount = (float)$entity->{'get'.ucfirst($entity->getValuableField())}();
        $fromCurrency = strtoupper($entity->getCurrency());
        $toCurrencyNormalized = strtoupper($toCurrency ?? $fromCurrency);

        $date = $this->resolveFxDate($entity);
        $snapshot = $this->fxRateSnapshotResolver->getClosestOrFetch($date);

        if ($fromCurrency === $toCurrencyNormalized) {
            return $amount;
        }

        $value = $snapshot->convert($amount, $fromCurrency, $toCurrencyNormalized);
        if ($value === null) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Missing conversion rate %s -> %s on %s',
                    $fromCurrency,
                    $toCurrencyNormalized,
                    $snapshot->getEffectiveAt()->format('Y-m-d')
                )
            );
        }

        return $value;
    }

    private function getBaseCurrency(): string
    {
        /** @var User $user */
        $user = $this->security->getUser();

        return $user->getBaseCurrency();
    }

    /**
     * Resolves date for FX lookup.
     * - For Transaction: executedAt
     * - For Debt (or others): "now" (latest available rate)
     */
    private function resolveFxDate(Transaction|Debt $entity): \DateTimeInterface
    {
        if ($entity instanceof Transaction) {
            $date = $entity->getExecutedAt();
            if ($date instanceof \DateTimeInterface) {
                return $date;
            }

            throw new \InvalidArgumentException('Transaction has no valid executedAt date for FX lookup.');
        }

        // For Debt (and any non-Transaction Valuable entity) we use "now" as FX date.
        return CarbonImmutable::now();
    }
}
