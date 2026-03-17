<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * Maps a query string parameter to a CarbonInterval instance.
 * Requires 'after' and 'before' to already be resolved as CarbonImmutable
 * in the request attributes (done by CarbonDateValueResolver).
 *
 * Usage:
 *   public function action(
 *       #[MapCarbonDate] CarbonImmutable $after,
 *       #[MapCarbonDate] CarbonImmutable $before,
 *       #[MapCarbonInterval(default: '1 month')] CarbonInterval $interval,
 *   ): Response
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapCarbonInterval
{
    public function __construct(
        public readonly ?string $default = null,
    ) {
    }
}
