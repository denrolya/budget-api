<?php

namespace App\Request\ValueResolver;

use App\Attribute\MapCarbonInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateInterval;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

final class CarbonIntervalValueResolver implements ValueResolverInterface
{
    private const INVALID_INTERVAL_MESSAGE = 'Invalid interval given.';

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        // Use attribute presence as the primary guard so this resolver always
        // wins over RequestAttributeValueResolver for MapCarbonInterval params.
        /** @var MapCarbonInterval|null $attr */
        $attr = $argument->getAttributesOfType(MapCarbonInterval::class)[0] ?? null;
        if ($attr === null) {
            return [];
        }

        // Sanity-check: parameter must be typed as CarbonInterval (or nullable).
        $type = $argument->getType() ?? '';
        if (!str_contains($type, 'CarbonInterval')) {
            return [];
        }

        $name = $argument->getName();

        // Read from query string first; fall back to request attributes where
        // FOSRest may have stored its QueryParam default (e.g. default: false).
        $value = $request->query->get($name);
        if ($value === null) {
            $value = $request->attributes->get($name);
        }

        // Require before/after to be present (set earlier by CarbonDateValueResolver)
        $before = $request->attributes->get('before');
        $after  = $request->attributes->get('after');

        if (!$before instanceof CarbonImmutable || !$after instanceof CarbonImmutable) {
            throw new BadRequestException(self::INVALID_INTERVAL_MESSAGE);
        }

        // Param was genuinely absent with no default → null for nullable, skip for non-nullable.
        if ($value === null) {
            // Still respect the attribute's own default (for non-FOSRest usage).
            if ($attr->default !== null) {
                $value = $attr->default;
            } elseif ($argument->isNullable()) {
                yield null;

                return;
            } else {
                return;
            }
        }

        $interval = $this->createInterval($value, $before, $after);

        if ($interval === null) {
            // A non-null value was provided but could not be parsed → always 400.
            throw new BadRequestException(self::INVALID_INTERVAL_MESSAGE);
        }

        yield $interval;
    }

    private function createInterval(
        mixed $value,
        CarbonImmutable $before,
        CarbonImmutable $after,
    ): ?CarbonInterval {
        // false / 0 / empty string → one period spanning the entire date range
        if ($value === 'false' || $value === false || $value === '0' || $value === 0 || $value === '') {
            return CarbonInterval::seconds(abs($before->diffInSeconds($after)) + 1);
        }

        // true / 1 → millisecond-precision auto-partitioning
        if ($value === 'true' || $value === true || $value === '1' || $value === 1) {
            $milliseconds = ($before->timestamp - $after->timestamp) / .06;

            return CarbonInterval::milliseconds((int) $milliseconds);
        }

        // ISO 8601 duration string (e.g. "P1M", "P7D")
        if (str_starts_with($value, 'P')) {
            return CarbonInterval::instance(new DateInterval($value));
        }

        // Natural language (e.g. "1 month", "7 days").
        // createFromDateString returns a zero-duration interval for unparseable strings, not false.
        $interval = CarbonInterval::createFromDateString($value);
        if (!$interval || (int) $interval->totalSeconds === 0) {
            return null; // → caller throws 400
        }

        return $interval;
    }
}
