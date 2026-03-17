<?php

declare(strict_types=1);

namespace App\DTO;

readonly class CategorizationResult
{
    public function __construct(
        public int $categoryId,
        /** Display note: cleaned historical note on match, raw bank string on no-match. */
        public string $note,
        /** 1.0 = exact match, 0.82..1.0 = fuzzy match, 0.0 = fallback (Unknown category). */
        public float $confidence,
    ) {
    }
}
