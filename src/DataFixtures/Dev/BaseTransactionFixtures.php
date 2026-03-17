<?php

declare(strict_types=1);

namespace App\DataFixtures\Dev;

use App\EventListener\DebtConvertedValueListener;
use App\EventListener\TransactionListener;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

abstract class BaseTransactionFixtures extends Fixture implements DependentFixtureInterface
{
    protected ParameterBagInterface $params;
    protected TransactionListener $transactionListener;
    protected DebtConvertedValueListener $valuableEntityListener;

    public function __construct(
        ParameterBagInterface $params,
        TransactionListener $transactionListener,
        DebtConvertedValueListener $valuableEntityListener,
    ) {
        $this->params = $params;
        $this->transactionListener = $transactionListener;
        $this->valuableEntityListener = $valuableEntityListener;
    }

    protected function disableListeners(): void
    {
        $this->transactionListener->setEnabled(false);
        $this->valuableEntityListener->setEnabled(false);
    }

    protected function enableListeners(): void
    {
        $this->transactionListener->setEnabled(true);
        $this->valuableEntityListener->setEnabled(true);
    }

    protected function convertAmount(float $amount, string $currency, array $allowedCurrencies): array
    {
        $conversionRates = ['USD' => 1.0, 'EUR' => 0.9, 'UAH' => 28.0, 'HUF' => 350.0, 'BTC' => 0.00002];
        $baseRate = $conversionRates[$currency] ?? 1.0;
        $usdAmount = $amount / $baseRate;
        $convertedValues = [];
        foreach ($allowedCurrencies as $targetCurrency) {
            $rate = $conversionRates[$targetCurrency] ?? 1.0;
            $precision = 'BTC' === $targetCurrency ? 8 : 2;
            $convertedValues[$targetCurrency] = round($usdAmount * $rate, $precision);
        }

        return $convertedValues;
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
            AccountFixtures::class,
            ExpenseCategoryFixtures::class,
            IncomeCategoryFixtures::class,
            ExchangeRateFixtures::class,
        ];
    }
}
