<?php

namespace App\Request\ParamConverter;

use Carbon\CarbonImmutable;
use Exception;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Request\ParamConverter\ParamConverterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CarbonParamConverter implements ParamConverterInterface
{
    /**
     * @{inheritdoc}
     *
     * @param Request $request
     * @param ParamConverter $configuration
     * @return bool
     */
    public function apply(Request $request, ParamConverter $configuration): bool
    {
        $param = $configuration->getName();

        if (!$request->attributes->has($param)) {
            return false;
        }

        $options = $configuration->getOptions();
        $value = $request->attributes->get($param);

        if (!$value && !array_key_exists('default', $options)) {
            return false;
        }

        $invalidDateMessage = 'Invalid date given.';

        try {
            if (!$value && array_key_exists('default', $options)) {
                $date = CarbonImmutable::parse(($options['default']))->startOfDay();
            } else {
                $date = isset($options['format'])
                    ? CarbonImmutable::createFromFormat($options['format'], $value)
                    : CarbonImmutable::parse($value);

                $startOfDayParams = ['from', 'after'];
                $endOfDayParams = ['to', 'before'];

                if (in_array($param, $startOfDayParams)) {
                    $date = $date->startOfDay();
                } elseif (in_array($param, $endOfDayParams)) {
                    $date = $date->endOfDay();
                }
            }
        } catch (Exception $e) {
            throw new NotFoundHttpException($invalidDateMessage);
        }

        $request->attributes->set($param, $date);

        return true;
    }

    /**
     * @{inheritdoc}
     *
     * @param ParamConverter $configuration
     * @return bool
     */
    public function supports(ParamConverter $configuration): bool
    {
        if (null === $configuration->getClass()) {
            return false;
        }

        return CarbonImmutable::class === $configuration->getClass();
    }
}
