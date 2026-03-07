<?php

namespace App\Request\ValueResolver;

use App\Attribute\MapCarbonDate;
use Carbon\CarbonImmutable;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CarbonDateValueResolver implements ValueResolverInterface
{
    private const START_OF_DAY_PARAMS = ['from', 'after'];
    private const END_OF_DAY_PARAMS   = ['to', 'before'];

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== CarbonImmutable::class) {
            return [];
        }

        /** @var MapCarbonDate|null $attr */
        $attr    = $argument->getAttributesOfType(MapCarbonDate::class)[0] ?? null;
        $format  = $attr?->format ?? 'Y-m-d';
        $default = $attr?->default;
        $name    = $argument->getName();
        $value   = $request->query->get($name);

        if (!$value && $default === null) {
            return [];
        }

        try {
            if (!$value && $default !== null) {
                $date = CarbonImmutable::parse($default)->startOfDay();
            } else {
                $date = CarbonImmutable::createFromFormat($format, $value);

                if (in_array($name, self::START_OF_DAY_PARAMS, true)) {
                    $date = $date->startOfDay();
                } elseif (in_array($name, self::END_OF_DAY_PARAMS, true)) {
                    $date = $date->endOfDay();
                }
            }
        } catch (Exception) {
            throw new NotFoundHttpException('Invalid date given.');
        }

        // Store in request attributes so CarbonIntervalValueResolver can access
        // after/before without re-parsing them from the raw query string.
        $request->attributes->set($name, $date);

        yield $date;
    }
}
