<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonInterface;
use DateTimeInterface;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class CSVExporter
{
    public function __construct(
        private TransactionRepository $transactions,
        private CategoryRepository $categories,
    ) {
    }

    public function stream(
        CarbonInterface $after,
        CarbonInterface $before,
        ?string $type,
        ?array $categoryFilter,
        ?array $accountFilter,
        ?array $excludedCategories,
        bool $withNestedCategories,
        ?bool $isDraft,
        bool $affectingProfitOnly = true,
        array $currencies = [],
        ?string $note = null,
        ?float $amountGte = null,
        ?float $amountLte = null,
        ?array $debts = null,
    ): StreamedResponse {
        $categoryFilter = $this->normalizeIdsOrEntities($categoryFilter);
        $accountFilter = $this->normalizeIdsOrEntities($accountFilter);
        $excludedCategories = $this->normalizeIdsOrEntities($excludedCategories);

        if ($withNestedCategories && [] !== $categoryFilter) {
            $categoryFilter = $this->categories->getCategoriesWithDescendantsByType($categoryFilter, $type);
        }

        return new StreamedResponse(function () use (
            $after,
            $before,
            $type,
            $categoryFilter,
            $accountFilter,
            $excludedCategories,
            $isDraft,
            $affectingProfitOnly,
            $currencies,
            $note,
            $amountGte,
            $amountLte,
            $debts
        ) {
            $out = fopen('php://output', 'w');

            // Optional: helps Excel recognize UTF-8
            fwrite($out, "\xEF\xBB\xBF");

            $csv = Writer::createFromStream($out);
            $csv->setDelimiter(',');
            $csv->setNewline("\r\n");

            // Header row — keep stable for consumers
            $csv->insertOne([
                'id',
                'executedAt',
                'type',
                'amount',
                'currency',
                'account_id',
                'account_name',
                'category_id',
                'category_name',
                'note',
                'is_draft',
                'created_at',
                'updated_at',
            ]);

            $items = $this->transactions->getList(
                after: $after,
                before: $before,
                type: $type,
                categories: $categoryFilter,
                accounts: $accountFilter,
                excludedCategories: $excludedCategories,
                affectingProfitOnly: $affectingProfitOnly,
                isDraft: $isDraft,
                note: $note,
                amountGte: $amountGte,
                amountLte: $amountLte,
                debts: $debts ?? [],
                currencies: $currencies,
                orderField: TransactionRepository::ORDER_FIELD,
                order: TransactionRepository::ORDER,
            );

            foreach ($items as $transaction) {
                $account = $transaction->getAccount();
                $category = $transaction->getCategory();

                $csv->insertOne([
                    $transaction->getId(),
                    $transaction->getExecutedAt()?->format(DateTimeInterface::ATOM),
                    $transaction->getType(),
                    (string) $transaction->getAmount(),
                    $transaction->getCurrency(),
                    $account->getId(),
                    $account->getName(),
                    $category->getId(),
                    $category->getName(),
                    $transaction->getNote(),
                    (int) $transaction->getIsDraft(),
                    $transaction->getCreatedAt()?->format(DateTimeInterface::ATOM),
                    $transaction->getUpdatedAt()?->format(DateTimeInterface::ATOM),
                ]);
            }

            fclose($out);
        });
    }

    /**
     * Allows both ids and entities as filter values.
     *
     * @return array<int|object>
     */
    private function normalizeIdsOrEntities(?array $values): array
    {
        if (null === $values || [] === $values) {
            return [];
        }

        $out = [];
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
                continue;
            }

            if (\is_object($v) && method_exists($v, 'getId')) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
