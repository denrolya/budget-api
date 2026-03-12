<?php

namespace App\Service;

use App\DTO\CategorizationResult;
use App\Entity\Category;
use Doctrine\DBAL\Connection;

/**
 * Classifies a raw bank note string into a category using a historical transaction index.
 *
 * Algorithm per suggest() call:
 *   1. Exact match on the normalised note string — O(1).
 *   2. Token-set fuzzy match (rapidfuzz token_set_ratio) — O(n index size).
 *      Query tokens are pre-computed once; index entry tokens are pre-computed at buildIndex() time.
 *      Early exit when a perfect score is found.
 *   3. Fallback → "Unknown" category.
 *
 * The index must be built explicitly via buildIndex(int $ownerId, bool $isIncome) or
 * buildAllIndexes(int $ownerId) before calling suggest(). Call resetIndex() before
 * each sync run to avoid stale data in long-lived processes.
 *
 * This service is bank-agnostic: usable from polling sync, webhook handlers, CSV import, etc.
 */
class TransactionCategorizationService
{
    private const FUZZY_THRESHOLD      = 0.82;
    private const INDEX_LOOKBACK_YEARS = 2;

    // Regex patterns declared as constants so the PHP engine caches the compiled form.
    private const RE_TRAILING_DATE   = '/\s+(\d{2}[\.\-\/]\d{2}([\.\-\/]\d{2,4})?|\d{4}[\.\-\/]\d{2}[\.\-\/]\d{2})\s*$/u';
    private const RE_LONG_NUMERIC    = '/\b\d{4,}\b/u';
    private const RE_DOMAIN_SUFFIXES = '/\.(com|io|net|org|ua|co|eu|de|uk|at|hu)\b/iu';
    private const RE_COLLAPSE_WS     = '/\s+/u';

    /**
     * System-managed category names excluded from the index.
     * Matching against these would incorrectly auto-classify transfers/debts as real spending.
     */
    private const SYSTEM_CATEGORY_NAMES = [
        Category::CATEGORY_TRANSFER,
        Category::CATEGORY_DEBT,
        Category::CATEGORY_TRANSFER_FEE,
    ];

    /**
     * Fallback/unknown category IDs excluded from the index by ID (not name).
     * These are assigned when no match is found — including them would poison future suggestions,
     * causing a self-reinforcing loop where "Card payment → Balance Adjustment" trains the index
     * to keep assigning "Balance Adjustment" to every unrecognised bank note.
     */
    private const FALLBACK_CATEGORY_IDS = [
        Category::EXPENSE_CATEGORY_ID_UNKNOWN,
        Category::INCOME_CATEGORY_ID_UNKNOWN,
    ];

    /** @var array<string, array{categoryId: int, displayNote: string, sortedTokens: string}> */
    private array $expenseIndex = [];

    /** @var array<string, array{categoryId: int, displayNote: string, sortedTokens: string}> */
    private array $incomeIndex = [];

    private bool $expenseIndexBuilt = false;
    private bool $incomeIndexBuilt  = false;
    private ?int $ownerId           = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Build both expense and income indexes in a single DB round-trip.
     * Prefer this over calling buildIndex() twice at the start of a sync run.
     */
    public function buildAllIndexes(int $ownerId): void
    {
        $this->ownerId = $ownerId;
        $since      = (new \DateTimeImmutable(sprintf('-%d years', self::INDEX_LOOKBACK_YEARS)))->format('Y-m-d');
        $nameList   = $this->inPlaceholders(self::SYSTEM_CATEGORY_NAMES);
        $idList     = $this->inPlaceholders(self::FALLBACK_CATEGORY_IDS);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.note, t.category_id, t.type, COUNT(*) AS cnt
             FROM transaction t
             INNER JOIN category c ON c.id = t.category_id
             WHERE t.is_draft = 0
               AND t.owner_id = ?
               AND t.executed_at >= ?
               AND t.type IN ('income', 'expense')
               AND t.note IS NOT NULL
               AND t.note != ''
               AND t.category_id IS NOT NULL
               AND c.name NOT IN ({$nameList})
               AND t.category_id NOT IN ({$idList})
             GROUP BY t.note, t.category_id, t.type",
            array_merge([$ownerId, $since], self::SYSTEM_CATEGORY_NAMES, self::FALLBACK_CATEGORY_IDS),
        );

        $expenseAcc = [];
        $incomeAcc  = [];

        foreach ($rows as $row) {
            $key = $this->normalize((string) $row['note']);
            if ($key === '') {
                continue;
            }
            if ($row['type'] === 'income') {
                $this->accumulateRow($incomeAcc, $key, (int) $row['category_id'], (int) $row['cnt'], (string) $row['note']);
            } else {
                $this->accumulateRow($expenseAcc, $key, (int) $row['category_id'], (int) $row['cnt'], (string) $row['note']);
            }
        }

        $this->expenseIndex      = $this->resolveIndex($expenseAcc);
        $this->incomeIndex       = $this->resolveIndex($incomeAcc);
        $this->expenseIndexBuilt = true;
        $this->incomeIndexBuilt  = true;
    }

    /**
     * Build the index for a single transaction type.
     * For syncing both types at once, prefer buildAllIndexes() (one DB query instead of two).
     */
    public function buildIndex(int $ownerId, bool $isIncome): void
    {
        $this->ownerId = $ownerId;
        $type     = $isIncome ? 'income' : 'expense';
        $since    = (new \DateTimeImmutable(sprintf('-%d years', self::INDEX_LOOKBACK_YEARS)))->format('Y-m-d');
        $nameList = $this->inPlaceholders(self::SYSTEM_CATEGORY_NAMES);
        $idList   = $this->inPlaceholders(self::FALLBACK_CATEGORY_IDS);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.note, t.category_id, COUNT(*) AS cnt
             FROM transaction t
             INNER JOIN category c ON c.id = t.category_id
             WHERE t.is_draft = 0
               AND t.owner_id = ?
               AND t.executed_at >= ?
               AND t.type = ?
               AND t.note IS NOT NULL
               AND t.note != ''
               AND t.category_id IS NOT NULL
               AND c.name NOT IN ({$nameList})
               AND t.category_id NOT IN ({$idList})
             GROUP BY t.note, t.category_id",
            array_merge([$ownerId, $since, $type], self::SYSTEM_CATEGORY_NAMES, self::FALLBACK_CATEGORY_IDS),
        );

        $acc = [];
        foreach ($rows as $row) {
            $key = $this->normalize((string) $row['note']);
            if ($key === '') {
                continue;
            }
            $this->accumulateRow($acc, $key, (int) $row['category_id'], (int) $row['cnt'], (string) $row['note']);
        }

        $index = $this->resolveIndex($acc);

        if ($isIncome) {
            $this->incomeIndex      = $index;
            $this->incomeIndexBuilt = true;
        } else {
            $this->expenseIndex      = $index;
            $this->expenseIndexBuilt = true;
        }
    }

    /**
     * Clear the in-memory index so the next suggest() triggers a fresh buildIndex().
     * Call this at the start of each sync run in long-lived processes (e.g. CLI commands).
     */
    public function resetIndex(): void
    {
        $this->expenseIndex      = [];
        $this->incomeIndex       = [];
        $this->expenseIndexBuilt = false;
        $this->incomeIndexBuilt  = false;
        $this->ownerId           = null;
    }

    /**
     * Suggest a category for the given raw bank note string.
     *
     * The relevant index must be built via buildIndex() or buildAllIndexes() before calling this.
     *
     * @return CategorizationResult  confidence=1.0 → exact/subset-token, 0.82..1.0 → fuzzy, 0.0 → fallback
     */
    public function suggest(string $rawNote, bool $isIncome): CategorizationResult
    {
        if ($isIncome && !$this->incomeIndexBuilt) {
            throw new \LogicException('Income index not built. Call buildIndex() or buildAllIndexes() before suggest().');
        }
        if (!$isIncome && !$this->expenseIndexBuilt) {
            throw new \LogicException('Expense index not built. Call buildIndex() or buildAllIndexes() before suggest().');
        }

        $index      = $isIncome ? $this->incomeIndex : $this->expenseIndex;
        $normalized = $this->normalize($rawNote);

        // 1. Exact match — O(1).
        if (isset($index[$normalized])) {
            return new CategorizationResult(
                categoryId: $index[$normalized]['categoryId'],
                note: $index[$normalized]['displayNote'],
                confidence: 1.0,
            );
        }

        // 2. Fuzzy token-set match.
        //    Pre-compute sorted query tokens once — avoids repeating this work per index entry.
        $queryTokens = $this->tokenize($normalized);
        $bestScore   = 0.0;
        $bestEntry   = null;

        foreach ($index as $entry) {
            $score = $this->tokenSetRatioFromTokenized($queryTokens, $entry['sortedTokens']);

            if ($score === 1.0) {
                // Can't improve further — return immediately.
                return new CategorizationResult(
                    categoryId: $entry['categoryId'],
                    note: $entry['displayNote'],
                    confidence: 1.0,
                );
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestEntry = $entry;
            }
        }

        if ($bestScore >= self::FUZZY_THRESHOLD && $bestEntry !== null) {
            return new CategorizationResult(
                categoryId: $bestEntry['categoryId'],
                note: $bestEntry['displayNote'],
                confidence: $bestScore,
            );
        }

        // 3. Fallback → Unknown category; preserve raw bank string as note.
        $fallbackId = $isIncome
            ? Category::INCOME_CATEGORY_ID_UNKNOWN
            : Category::EXPENSE_CATEGORY_ID_UNKNOWN;

        return new CategorizationResult(
            categoryId: $fallbackId,
            note: $rawNote,
            confidence: 0.0,
        );
    }

    /**
     * Normalise a raw bank note for index lookups.
     *
     * Steps (order matters):
     *   1. Lowercase.
     *   2. Strip trailing date patterns (01.01 / 2024-01-01 / 01/01/24).
     *   3. Strip long numeric references (5+ consecutive digits — transaction IDs, card numbers).
     *   4. Replace * # @ | with space.
     *   5. Strip common domain suffixes (.com .io .net .org .ua).
     *   6. Collapse whitespace.
     */
    public function normalize(string $note): string
    {
        $s = mb_strtolower($note);
        $s = preg_replace(self::RE_TRAILING_DATE, ' ', $s) ?? $s;
        $s = preg_replace(self::RE_LONG_NUMERIC, ' ', $s) ?? $s;
        $s = str_replace(['*', '#', '@', '|', '\\'], ' ', $s);
        $s = preg_replace(self::RE_DOMAIN_SUFFIXES, '', $s) ?? $s;

        return trim(preg_replace(self::RE_COLLAPSE_WS, ' ', $s) ?? $s);
    }

    /**
     * Token-set similarity ratio (rapidfuzz token_set_ratio algorithm).
     *
     * Decomposes both strings into sorted unique tokens, then computes three pairwise ratios
     * using the intersection (t1), intersection+remainders-A (t2), intersection+remainders-B (t3):
     *   score = max(ratio(t1,t2), ratio(t1,t3), ratio(t2,t3))
     *
     * This correctly handles subset matches:
     *   "nova poshta" vs "nova poshta lviv" → 1.0 (t1 == t2, ratio = 1.0)
     *
     * @return float 0.0..1.0
     */
    public function tokenSetRatio(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        return $this->tokenSetRatioFromTokenized($this->tokenize($a), $this->tokenize($b));
    }

    /**
     * Tokenize a normalized string into a sorted, unique-token string.
     * Pre-computed per index entry so the fuzzy loop only tokenizes the query string.
     */
    public function tokenize(string $s): string
    {
        if ($s === '') {
            return '';
        }

        $tokens = array_values(array_unique(array_filter(explode(' ', $s))));
        sort($tokens);

        return implode(' ', $tokens);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Accumulate a SQL row into the working accumulator array.
     *
     * @param array<string, array<int, array{count: int, displayNote: string}>> $accumulator
     */
    private function accumulateRow(array &$accumulator, string $key, int $catId, int $cnt, string $rawNote): void
    {
        $accumulator[$key] ??= [];
        if (!isset($accumulator[$key][$catId])) {
            $accumulator[$key][$catId] = ['count' => 0, 'displayNote' => $rawNote];
        }
        $accumulator[$key][$catId]['count'] += $cnt;
    }

    /**
     * Resolve the accumulator into a flat index with pre-tokenized sorted-token strings.
     *
     * @param array<string, array<int, array{count: int, displayNote: string}>> $accumulator
     * @return array<string, array{categoryId: int, displayNote: string, sortedTokens: string}>
     */
    private function resolveIndex(array $accumulator): array
    {
        $index = [];
        foreach ($accumulator as $key => $categories) {
            $dominantId = array_key_first($categories);
            foreach ($categories as $catId => $data) {
                if ($data['count'] > $categories[$dominantId]['count']) {
                    $dominantId = $catId;
                }
            }
            $index[$key] = [
                'categoryId'   => $dominantId,
                'displayNote'  => $categories[$dominantId]['displayNote'],
                'sortedTokens' => $this->tokenize($key),
            ];
        }

        return $index;
    }

    /**
     * Proper rapidfuzz token_set_ratio computed from pre-tokenized strings.
     *
     * Accepts sorted-unique-token strings (output of tokenize()) for both sides,
     * avoiding re-tokenization of the indexed string on every comparison.
     *
     * @return float 0.0..1.0
     */
    private function tokenSetRatioFromTokenized(string $sortedA, string $sortedB): float
    {
        if ($sortedA === '' || $sortedB === '') {
            return 0.0;
        }

        $setA = array_flip(explode(' ', $sortedA));
        $setB = array_flip(explode(' ', $sortedB));

        $intersection = array_keys(array_intersect_key($setA, $setB));
        sort($intersection);

        $remainA = array_keys(array_diff_key($setA, $setB));
        sort($remainA);

        $remainB = array_keys(array_diff_key($setB, $setA));
        sort($remainB);

        $t1 = implode(' ', $intersection);
        $t2 = trim($t1 . ' ' . implode(' ', $remainA));
        $t3 = trim($t1 . ' ' . implode(' ', $remainB));

        $score = 0.0;
        similar_text($t1, $t2, $pct); $score = max($score, $pct / 100.0);
        similar_text($t1, $t3, $pct); $score = max($score, $pct / 100.0);
        similar_text($t2, $t3, $pct); $score = max($score, $pct / 100.0);

        return $score;
    }

    /** Returns '?,?,?' positional placeholders for N items. */
    private function inPlaceholders(array $items): string
    {
        return implode(',', array_fill(0, count($items), '?'));
    }
}

