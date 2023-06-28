<?php

namespace App\Request\ParamConverter;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Exception;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Request\ParamConverter\ParamConverterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CarbonIntervalParamConverter implements ParamConverterInterface
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

        $invalidDateMessage = 'Invalid interval given.';

        try {
            if(!$value && $options['default']) {
                $interval = CarbonInterval::createFromDateString($options['default']);
            } elseif($value) {
                $interval = CarbonInterval::createFromDateString($value);
            } else {
                $interval = null;
            }
        } catch (Exception $e) {
            throw new NotFoundHttpException($invalidDateMessage);
        }

        $request->attributes->set($param, $interval);

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

        return CarbonInterval::class === $configuration->getClass();
    }
}
