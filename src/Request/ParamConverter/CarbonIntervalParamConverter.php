<?php

namespace App\Request\ParamConverter;

use Carbon\CarbonInterval;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Request\ParamConverter\ParamConverterInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

class CarbonIntervalParamConverter implements ParamConverterInterface
{
    private const INVALID_INTERVAL_MESSAGE = 'Invalid interval given.';

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

        if (!$request->attributes->has($param)
            || !$request->attributes->has('before')
            || !$request->attributes->has('after')
        ) {
            throw new BadRequestException(self::INVALID_INTERVAL_MESSAGE);
        }

        $options = $configuration->getOptions();
        $value = $request->attributes->get($param);

        if (!$interval = $this->createInterval($value, $options, $request)) {
            throw new BadRequestException(self::INVALID_INTERVAL_MESSAGE);
        }

        $request->attributes->set($param, $interval);

        return true;
    }

    private function createInterval($value, array $options, Request $request): CarbonInterval|bool
    {
        $default = $options['default'] ?? null;
        if (strpos($value, 'P') === 0) {
            $interval = CarbonInterval::instance(new \DateInterval($value));
        } else {
            $interval = CarbonInterval::createFromDateString($value);
        }

        if ($value === 'false' || $value === false || $value === '0' || $value === 0 || (!$value && !$default)) {
            $interval = $request->attributes->get('before')->diffAsCarbonInterval($request->attributes->get('after'));
        }

        if ($value === 'true' || $value === true || $value === '1' || $value === 1) {
            $beforeTimestamp = $request->attributes->get('before')->timestamp;
            $afterTimestamp = $request->attributes->get('after')->timestamp;
            $milliseconds = ($beforeTimestamp - $afterTimestamp) / .06;
            $interval = CarbonInterval::milliseconds($milliseconds);
        }

        if ($value === '' && $default) {
            $interval = CarbonInterval::createFromDateString($options['default']);
        }

        return $interval;
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

        return CarbonInterval::class === $configuration->getClass();
    }
}
