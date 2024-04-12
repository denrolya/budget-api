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
            $interval = $this->createInterval($value, $options, $request);
        } catch (Exception $e) {
            throw new NotFoundHttpException($invalidIntervalMessage);
        }

        $request->attributes->set($param, $interval);

        return true;
    }

    private function createInterval($value, array $options, Request $request): CarbonInterval
    {
        $value = is_numeric($value) ? (int)$value : (bool)$value;

        switch ($value) {
            case false:
                return $request->attributes->get('before')->diffAsCarbonInterval($request->attributes->get('after'));
            case true:
                $milliseconds = ($request->attributes->get('before')->timestamp - $request->attributes->get('after')->timestamp) / .06;
                return CarbonInterval::milliseconds($milliseconds);
            case '' && $options['default']:
                return CarbonInterval::createFromDateString($options['default']);
            default:
                return CarbonInterval::createFromDateString($value);
        }
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
