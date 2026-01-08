<?php

namespace App\Service;

use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use Carbon\CarbonInterface;
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
    ): StreamedResponse {
        $categoryFilter = $this->normalizeIdsOrEntities($categoryFilter);
        $accountFilter = $this->normalizeIdsOrEntities($accountFilter);
        $excludedCategories = $this->normalizeIdsOrEntities($excludedCategories);

        if ($withNestedCategories && !empty($categoryFilter)) {
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
            $affectingProfitOnly
        ) {
            $out = fopen('php://output', 'wb');

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
                orderField: TransactionRepository::ORDER_FIELD,
                order: TransactionRepository::ORDER
            );

            foreach ($items as $tx) {
                /** @var Transaction $tx */
                $account = $tx->getAccount();
                $category = $tx->getCategory();

                $csv->insertOne([
                    $tx->getId(),
                    $tx->getExecutedAt()?->format(\DateTimeInterface::ATOM),
                    $tx->getType(),
                    (string)$tx->getAmount(),
                    $tx->getCurrency(),
                    $account->getId(),
                    $account->getName(),
                    $category->getId(),
                    $category->getName(),
                    $tx->getNote(),
                    (int)$tx->getIsDraft(),
                    $tx->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                    $tx->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                ]);
            }

            fclose($out);
        });
    }

    /**
     * Allows both ids and entities as filter values.
     * @return array<int|object>
     */
    private function normalizeIdsOrEntities(?array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $out[] = (int)$v;
                continue;
            }

            if (is_object($v) && method_exists($v, 'getId')) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
