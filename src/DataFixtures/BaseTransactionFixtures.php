<?php

namespace App\DataFixtures;

use App\EventListener\TransactionListener;
use App\EventListener\ValuableEntityEventListener;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

abstract class BaseTransactionFixtures extends Fixture implements DependentFixtureInterface
{
    protected ParameterBagInterface $params;

    protected TransactionListener $transactionListener;

    protected ValuableEntityEventListener $valuableEntityListener;

    public function __construct(
        ParameterBagInterface $params,
        TransactionListener $transactionListener,
        ValuableEntityEventListener $valuableEntityListener
    ) {
        $this->params = $params;
        $this->transactionListener = $transactionListener;
        $this->valuableEntityListener = $valuableEntityListener;
    }

    /**
     * Disables relevant listeners before loading fixtures.
     */
    protected function disableListeners(): void
    {
        $this->transactionListener->setEnabled(false);
        $this->valuableEntityListener->setEnabled(false);
    }

    /**
     * Re-enables relevant listeners after loading fixtures.
     */
    protected function enableListeners(): void
    {
        $this->transactionListener->setEnabled(true);
        $this->valuableEntityListener->setEnabled(true);
    }

    /**
     * Converts the amount to each allowed currency, with higher precision for BTC.
     *
     * @param float $amount
     * @param string $currency
     * @param array $allowedCurrencies
     * @return array
     */
    protected function convertAmount(float $amount, string $currency, array $allowedCurrencies): array
    {
        $conversionRates = [
            'USD' => 1.0,
            'EUR' => 0.9,
            'UAH' => 28.0,
            'HUF' => 350.0,
            'BTC' => 0.00002,
        ];

        $baseRate = $conversionRates[$currency] ?? 1.0;
        $usdAmount = $amount / $baseRate;

        $convertedValues = [];
        foreach ($allowedCurrencies as $targetCurrency) {
            $rate = $conversionRates[$targetCurrency] ?? 1.0;
            $precision = $targetCurrency === 'BTC' ? 8 : 2;
            $convertedValues[$targetCurrency] = round($usdAmount * $rate, $precision);
        }

        return $convertedValues;
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            AccountFixtures::class,
            IncomeCategoryFixtures::class,
            ExpenseCategoryFixtures::class,
        ];
    }
}
