<?php

namespace App\Attribute;

use Attribute;

/**
 * Maps a query string parameter to a CarbonImmutable instance.
 *
 * Usage:
 *   public function action(
 *       #[MapCarbonDate(format: 'Y-m-d', default: 'first day of this month')] CarbonImmutable $after,
 *   ): Response
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapCarbonDate
{
    public function __construct(
        public readonly string $format = 'Y-m-d',
        public readonly ?string $default = null,
    ) {}
}
