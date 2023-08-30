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

        if(!$request->attributes->has($param) || (!($request->attributes->has('before') && $request->attributes->has('after')))) {
            return false;
        }

        $options = $configuration->getOptions();
        $value = $request->attributes->get($param);

        $invalidIntervalMessage = 'Invalid interval given.';

        try {
            if($value === 'false' || $value === false || $value === '0' || $value === 0 || (!$value && !array_key_exists('default', $options))) {
                $interval = $request->attributes->get('before')->diffAsCarbonInterval($request->attributes->get('after'));
            } elseif($value === 'true' || $value === true || $value === '1' || $value === 1) {
                $milliseconds = ($request->attributes->get('before')->timestamp - $request->attributes->get('after')->timestamp) / .06;
                $interval = CarbonInterval::milliseconds($milliseconds);
            } elseif($value === '' && $options['default']) {
                $interval = CarbonInterval::createFromDateString($options['default']);
            } elseif($value) {
                $interval = CarbonInterval::createFromDateString($value);
            }
        } catch (Exception $e) {
            throw new NotFoundHttpException($invalidIntervalMessage);
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
