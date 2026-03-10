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
        /** @var TransactionRepository $transactionRepo */
        $transactionRepo = $this->em->getRepository(Transaction::class);
        $this->transactionRepo = $transactionRepo;
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
