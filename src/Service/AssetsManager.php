<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Debt;
use App\Entity\Transaction;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Security\Core\Security;
use Traversable;

class AssetsManager
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly ExchangeRateSnapshotResolver $exchangeRateSnapshotResolver,
        private readonly Security $security,
    ) {
    }

    /** @return array{list: Traversable, totalValue: float, count: int} */
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
        ?array $currencies = null,
        int $perPage = TransactionRepository::PER_PAGE,
        int $page = 1,
        string $orderField = TransactionRepository::ORDER_FIELD,
        string $order = TransactionRepository::ORDER,
    ): array {
        $resolvedCategories = ($withChildCategories && null !== $categories && [] !== $categories)
            ? $this->categoryRepository->getCategoriesWithDescendantsByType($categories, $type)
            : ($categories ?? []);

        $paginator = $this->transactionRepository->getPaginator(
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
            'list' => $paginator->getIterator(),
            'totalValue' => round(
                $this->transactionRepository->sumConverted(
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
                2,
            ),
            'count' => $paginator->count(),
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
        $amount = (float) $entity->{'get' . ucfirst($entity->getValuableField())}();
        $fromCurrency = strtoupper($entity->getCurrency());

        $date = $this->resolveFxDate($entity);
        $snapshot = $this->exchangeRateSnapshotResolver->getClosestOrFetch($date);

        $result = [];
        foreach ($snapshot->getAvailableCurrencies() as $currency) {
            $value = $snapshot->convert($amount, $fromCurrency, $currency);
            if (null !== $value) {
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
    private function resolveFxDate(Transaction|Debt $entity): DateTimeInterface
    {
        if ($entity instanceof Transaction) {
            $date = $entity->getExecutedAt();
            if ($date instanceof DateTimeInterface) {
                return $date;
            }

            throw new \InvalidArgumentException('Transaction has no valid executedAt date for FX lookup.');
        }

        // For Debt (and any non-Transaction Valuable entity) we use "now" as FX date.
        return CarbonImmutable::now();
    }
}
