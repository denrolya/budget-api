<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExchangeRateSnapshot;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ExchangeRateSnapshotTest extends TestCase
{
    private function makeSnapshot(
        ?string $usdPerEur = null,
        ?string $hufPerEur = null,
        ?string $uahPerEur = null,
        ?string $eurPerBtc = null,
        ?string $eurPerEth = null,
    ): ExchangeRateSnapshot {
        $snapshot = new ExchangeRateSnapshot();

        if (null !== $usdPerEur) {
            $snapshot->setUsdPerEur($usdPerEur);
        }
        if (null !== $hufPerEur) {
            $snapshot->setHufPerEur($hufPerEur);
        }
        if (null !== $uahPerEur) {
            $snapshot->setUahPerEur($uahPerEur);
        }
        if (null !== $eurPerBtc) {
            $snapshot->setEurPerBtc($eurPerBtc);
        }
        if (null !== $eurPerEth) {
            $snapshot->setEurPerEth($eurPerEth);
        }

        return $snapshot;
    }

    // ---------------------------------------------------------------------------
    // Scalar getters return null when not set
    // ---------------------------------------------------------------------------

    public function testAllFloatGettersReturnNullWhenFieldsNotSet(): void
    {
        $snapshot = new ExchangeRateSnapshot();

        self::assertNull($snapshot->getUsdPerEurFloat());
        self::assertNull($snapshot->getEurPerUsdFloat());
        self::assertNull($snapshot->getHufPerEurFloat());
        self::assertNull($snapshot->getEurPerHufFloat());
        self::assertNull($snapshot->getUahPerEurFloat());
        self::assertNull($snapshot->getEurPerUahFloat());
        self::assertNull($snapshot->getEurPerBtcFloat());
        self::assertNull($snapshot->getBtcPerEurFloat());
        self::assertNull($snapshot->getEurPerEthFloat());
        self::assertNull($snapshot->getEthPerEurFloat());
    }

    // ---------------------------------------------------------------------------
    // Direct float getters
    // ---------------------------------------------------------------------------

    public function testGetUsdPerEurFloatCastsStringToFloat(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.08500000');

        self::assertEqualsWithDelta(1.085, $snapshot->getUsdPerEurFloat(), 0.000001);
    }

    public function testGetHufPerEurFloatCastsStringToFloat(): void
    {
        $snapshot = $this->makeSnapshot(hufPerEur: '390.123456');

        self::assertEqualsWithDelta(390.123456, $snapshot->getHufPerEurFloat(), 0.000001);
    }

    public function testGetUahPerEurFloatCastsStringToFloat(): void
    {
        $snapshot = $this->makeSnapshot(uahPerEur: '42.500000');

        self::assertEqualsWithDelta(42.5, $snapshot->getUahPerEurFloat(), 0.000001);
    }

    public function testGetEurPerBtcFloatCastsStringToFloat(): void
    {
        $snapshot = $this->makeSnapshot(eurPerBtc: '55000.12345678');

        self::assertEqualsWithDelta(55000.12345678, $snapshot->getEurPerBtcFloat(), 0.00001);
    }

    public function testGetEurPerEthFloatCastsStringToFloat(): void
    {
        $snapshot = $this->makeSnapshot(eurPerEth: '3200.00000000');

        self::assertEqualsWithDelta(3200.0, $snapshot->getEurPerEthFloat(), 0.000001);
    }

    // ---------------------------------------------------------------------------
    // Inverse getters
    // ---------------------------------------------------------------------------

    public function testGetEurPerUsdFloatIsReciprocal(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '2.0');

        self::assertEqualsWithDelta(0.5, $snapshot->getEurPerUsdFloat(), 0.000001);
    }

    public function testGetEurPerHufFloatIsReciprocal(): void
    {
        $snapshot = $this->makeSnapshot(hufPerEur: '400.0');

        self::assertEqualsWithDelta(0.0025, $snapshot->getEurPerHufFloat(), 0.0000001);
    }

    public function testGetEurPerUahFloatIsReciprocal(): void
    {
        $snapshot = $this->makeSnapshot(uahPerEur: '40.0');

        self::assertEqualsWithDelta(0.025, $snapshot->getEurPerUahFloat(), 0.0000001);
    }

    public function testGetBtcPerEurFloatIsReciprocalOfEurPerBtc(): void
    {
        // eurPerBtc = 50000 → btcPerEur = 1/50000 = 0.00002
        $snapshot = $this->makeSnapshot(eurPerBtc: '50000.0');

        self::assertEqualsWithDelta(0.00002, $snapshot->getBtcPerEurFloat(), 0.000000001);
    }

    public function testGetEthPerEurFloatIsReciprocalOfEurPerEth(): void
    {
        // eurPerEth = 2000 → ethPerEur = 1/2000 = 0.0005
        $snapshot = $this->makeSnapshot(eurPerEth: '2000.0');

        self::assertEqualsWithDelta(0.0005, $snapshot->getEthPerEurFloat(), 0.00000001);
    }

    // ---------------------------------------------------------------------------
    // Division-by-zero protection in inverse getters
    // ---------------------------------------------------------------------------

    public function testGetEurPerUsdFloatReturnsNullWhenUsdRateIsZero(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '0.0');

        self::assertNull($snapshot->getEurPerUsdFloat());
    }

    public function testGetEurPerHufFloatReturnsNullWhenHufRateIsZero(): void
    {
        $snapshot = $this->makeSnapshot(hufPerEur: '0.0');

        self::assertNull($snapshot->getEurPerHufFloat());
    }

    public function testGetEurPerUahFloatReturnsNullWhenUahRateIsZero(): void
    {
        $snapshot = $this->makeSnapshot(uahPerEur: '0.0');

        self::assertNull($snapshot->getEurPerUahFloat());
    }

    public function testGetBtcPerEurFloatReturnsNullWhenEurPerBtcIsZero(): void
    {
        $snapshot = $this->makeSnapshot(eurPerBtc: '0.0');

        self::assertNull($snapshot->getBtcPerEurFloat());
    }

    public function testGetEthPerEurFloatReturnsNullWhenEurPerEthIsZero(): void
    {
        $snapshot = $this->makeSnapshot(eurPerEth: '0.0');

        self::assertNull($snapshot->getEthPerEurFloat());
    }

    // ---------------------------------------------------------------------------
    // getRateFromEur()
    // ---------------------------------------------------------------------------

    public function testGetRateFromEurReturnsOneForEur(): void
    {
        $snapshot = new ExchangeRateSnapshot();

        self::assertEqualsWithDelta(1.0, $snapshot->getRateFromEur('EUR'), 0.000001);
    }

    public function testGetRateFromEurReturnsUsdRate(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertEqualsWithDelta(1.1, $snapshot->getRateFromEur('USD'), 0.000001);
    }

    public function testGetRateFromEurReturnsHufRate(): void
    {
        $snapshot = $this->makeSnapshot(hufPerEur: '390.0');

        self::assertEqualsWithDelta(390.0, $snapshot->getRateFromEur('HUF'), 0.000001);
    }

    public function testGetRateFromEurReturnsNullForUnknownCurrency(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertNull($snapshot->getRateFromEur('GBP'));
        self::assertNull($snapshot->getRateFromEur('CHF'));
        self::assertNull($snapshot->getRateFromEur(''));
    }

    public function testGetRateFromEurIsCaseInsensitive(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertEqualsWithDelta(1.1, $snapshot->getRateFromEur('usd'), 0.000001);
        self::assertEqualsWithDelta(1.1, $snapshot->getRateFromEur('Usd'), 0.000001);
    }

    // ---------------------------------------------------------------------------
    // getRateToEur()
    // ---------------------------------------------------------------------------

    public function testGetRateToEurReturnsOneForEur(): void
    {
        $snapshot = new ExchangeRateSnapshot();

        self::assertEqualsWithDelta(1.0, $snapshot->getRateToEur('EUR'), 0.000001);
    }

    public function testGetRateToEurReturnsInverseOfUsdRate(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '2.0');

        // 1 USD = 0.5 EUR
        self::assertEqualsWithDelta(0.5, $snapshot->getRateToEur('USD'), 0.000001);
    }

    public function testGetRateToEurReturnsNullForUnknownCurrency(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertNull($snapshot->getRateToEur('GBP'));
    }

    public function testGetRateToEurIsCaseInsensitive(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '2.0');

        self::assertEqualsWithDelta(0.5, $snapshot->getRateToEur('usd'), 0.000001);
    }

    // ---------------------------------------------------------------------------
    // convert()
    // ---------------------------------------------------------------------------

    public function testConvertSameCurrencyReturnsSameAmount(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1', hufPerEur: '390.0');

        self::assertEqualsWithDelta(250.0, $snapshot->convert(250.0, 'EUR', 'EUR'), 0.0001);
        self::assertEqualsWithDelta(100.0, $snapshot->convert(100.0, 'USD', 'USD'), 0.0001);
    }

    public function testConvertEurToUsd(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        // 100 EUR → USD: 100 * 1.0 * 1.1 = 110
        self::assertEqualsWithDelta(110.0, $snapshot->convert(100.0, 'EUR', 'USD'), 0.0001);
    }

    public function testConvertUsdToEur(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        // 110 USD → EUR: 110 * (1/1.1) * 1.0 = 100
        self::assertEqualsWithDelta(100.0, $snapshot->convert(110.0, 'USD', 'EUR'), 0.0001);
    }

    public function testConvertUsdToHuf(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1', hufPerEur: '390.0');

        // 1 USD → HUF: 1 * (1/1.1) * 390 ≈ 354.54
        $expected = 1.0 / 1.1 * 390.0;
        self::assertEqualsWithDelta($expected, $snapshot->convert(1.0, 'USD', 'HUF'), 0.01);
    }

    public function testConvertBtcToEur(): void
    {
        // eurPerBtc = 50000 → btcToEur = 1/50000; 1 BTC → EUR = 1 * (1/50000) * 50000? No...
        // Actually: rateToEur('BTC') = eurPerBtcFloat = 50000; amount * 50000 * 1.0
        $snapshot = $this->makeSnapshot(eurPerBtc: '50000.0');

        // 1 BTC → EUR: rateToEur = eurPerBtc = 50000, rateFromEur(EUR) = 1
        // amount * rateToEur * rateFromEur = 1 * 50000 * 1 = 50000
        self::assertEqualsWithDelta(50000.0, $snapshot->convert(1.0, 'BTC', 'EUR'), 0.01);
    }

    public function testConvertEurToBtc(): void
    {
        // eurPerBtc = 50000 → btcPerEur = 1/50000
        $snapshot = $this->makeSnapshot(eurPerBtc: '50000.0');

        // 50000 EUR → BTC: rateToEur('EUR') = 1, rateFromEur('BTC') = btcPerEur = 1/50000
        // 50000 * 1 * (1/50000) = 1 BTC
        self::assertEqualsWithDelta(1.0, $snapshot->convert(50000.0, 'EUR', 'BTC'), 0.000001);
    }

    public function testConvertReturnsNullWhenFromCurrencyRateIsNull(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1'); // HUF not set

        self::assertNull($snapshot->convert(100.0, 'HUF', 'USD'));
    }

    public function testConvertReturnsNullWhenToCurrencyRateIsNull(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1'); // HUF not set

        self::assertNull($snapshot->convert(100.0, 'EUR', 'HUF'));
    }

    public function testConvertReturnsNullForUnknownCurrencies(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertNull($snapshot->convert(100.0, 'GBP', 'EUR'));
        self::assertNull($snapshot->convert(100.0, 'EUR', 'GBP'));
    }

    public function testConvertIsCaseInsensitive(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        $upper = $snapshot->convert(100.0, 'EUR', 'USD');
        $lower = $snapshot->convert(100.0, 'eur', 'usd');

        self::assertEqualsWithDelta($upper, $lower, 0.000001);
    }

    public function testConvertZeroAmountReturnsZero(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertEqualsWithDelta(0.0, $snapshot->convert(0.0, 'EUR', 'USD'), 0.0001);
    }

    public function testConvertNegativeAmountPreservesSign(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertEqualsWithDelta(-110.0, $snapshot->convert(-100.0, 'EUR', 'USD'), 0.0001);
    }

    // ---------------------------------------------------------------------------
    // hasFiatRates() / hasCryptoRates()
    // ---------------------------------------------------------------------------

    public function testHasFiatRatesReturnsFalseWhenNoFiatSet(): void
    {
        $snapshot = $this->makeSnapshot(eurPerBtc: '50000.0');

        self::assertFalse($snapshot->hasFiatRates());
    }

    public function testHasFiatRatesReturnsTrueWhenAnyFiatIsSet(): void
    {
        self::assertTrue($this->makeSnapshot(usdPerEur: '1.1')->hasFiatRates());
        self::assertTrue($this->makeSnapshot(hufPerEur: '390.0')->hasFiatRates());
        self::assertTrue($this->makeSnapshot(uahPerEur: '40.0')->hasFiatRates());
    }

    public function testHasCryptoRatesReturnsFalseWhenNoCryptoSet(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1');

        self::assertFalse($snapshot->hasCryptoRates());
    }

    public function testHasCryptoRatesReturnsTrueWhenAnyFiatIsSet(): void
    {
        self::assertTrue($this->makeSnapshot(eurPerBtc: '50000.0')->hasCryptoRates());
        self::assertTrue($this->makeSnapshot(eurPerEth: '3000.0')->hasCryptoRates());
    }

    public function testHasFiatAndCryptoRatesTogetherBothReturnTrue(): void
    {
        $snapshot = $this->makeSnapshot(usdPerEur: '1.1', eurPerBtc: '50000.0');

        self::assertTrue($snapshot->hasFiatRates());
        self::assertTrue($snapshot->hasCryptoRates());
    }

    // ---------------------------------------------------------------------------
    // getAvailableCurrencies()
    // ---------------------------------------------------------------------------

    public function testGetAvailableCurrenciesAlwaysContainsEur(): void
    {
        $snapshot = new ExchangeRateSnapshot();

        self::assertContains('EUR', $snapshot->getAvailableCurrencies());
    }

    public function testGetAvailableCurrenciesReflectsSetFields(): void
    {
        $snapshot = $this->makeSnapshot(
            usdPerEur: '1.1',
            hufPerEur: '390.0',
            eurPerBtc: '50000.0',
        );

        $currencies = $snapshot->getAvailableCurrencies();

        self::assertContains('EUR', $currencies);
        self::assertContains('USD', $currencies);
        self::assertContains('HUF', $currencies);
        self::assertContains('BTC', $currencies);
        self::assertNotContains('UAH', $currencies);
        self::assertNotContains('ETH', $currencies);
    }

    public function testGetAvailableCurrenciesWithAllFieldsSet(): void
    {
        $snapshot = $this->makeSnapshot(
            usdPerEur: '1.1',
            hufPerEur: '390.0',
            uahPerEur: '40.0',
            eurPerBtc: '50000.0',
            eurPerEth: '3000.0',
        );

        $currencies = $snapshot->getAvailableCurrencies();

        self::assertCount(6, $currencies);
        self::assertContains('EUR', $currencies);
        self::assertContains('USD', $currencies);
        self::assertContains('HUF', $currencies);
        self::assertContains('UAH', $currencies);
        self::assertContains('BTC', $currencies);
        self::assertContains('ETH', $currencies);
    }

    // ---------------------------------------------------------------------------
    // setEffectiveAt / getEffectiveAt
    // ---------------------------------------------------------------------------

    public function testSetAndGetEffectiveAt(): void
    {
        $snapshot = new ExchangeRateSnapshot();
        $date = new DateTimeImmutable('2024-01-15 00:00:00');
        $snapshot->setEffectiveAt($date);

        self::assertEquals($date, $snapshot->getEffectiveAt());
    }

    public function testIdIsNullBeforePersistence(): void
    {
        $snapshot = new ExchangeRateSnapshot();

        self::assertNull($snapshot->getId());
    }
}
