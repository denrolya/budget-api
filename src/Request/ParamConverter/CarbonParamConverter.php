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

        if(!$request->attributes->has($param)) {
            return false;
        }

        $options = $configuration->getOptions();
        $value = $request->attributes->get($param);

        if(!$value && !array_key_exists('default', $options)) {
            return false;
        }

        $invalidDateMessage = 'Invalid date given.';

        try {
            if(!$value && array_key_exists('default', $options)) {
                $date = (new CarbonImmutable($options['default']))->startOfDay();
            } elseif($value) {
                $date = isset($options['format'])
                    ? CarbonImmutable::createFromFormat($options['format'], $value)
                    : new CarbonImmutable($value);

                if($param === 'from' || $param === 'after') {
                    $date = $date->startOfDay();
                } elseif($param === 'to' || $param === 'before') {
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
        if(null === $configuration->getClass()) {
            return false;
        }

        return CarbonImmutable::class === $configuration->getClass();
    }
}
