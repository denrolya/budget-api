<?php
namespace App\DataFixtures\Test;

use App\Entity\ExchangeRateSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ExchangeRateFixtures extends Fixture
{
    private const SNAPSHOTS = [
        '1991-01-01' => ['usd_per_eur' => '1.10', 'huf_per_eur' => '80.0', 'uah_per_eur' => '5.0', 'eur_per_btc' => null, 'eur_per_eth' => null],
        '2000-01-01' => ['usd_per_eur' => '0.95', 'huf_per_eur' => '250.0', 'uah_per_eur' => '6.0', 'eur_per_btc' => null, 'eur_per_eth' => null],
        '2010-01-01' => ['usd_per_eur' => '1.35', 'huf_per_eur' => '270.0', 'uah_per_eur' => '11.0', 'eur_per_btc' => '100.0', 'eur_per_eth' => null],
        '2020-01-01' => ['usd_per_eur' => '1.12', 'huf_per_eur' => '330.0', 'uah_per_eur' => '26.0', 'eur_per_btc' => '7000.0', 'eur_per_eth' => '130.0'],
        '2026-02-22' => ['usd_per_eur' => '1.05', 'huf_per_eur' => '400.0', 'uah_per_eur' => '40.0', 'eur_per_btc' => '50000.0', 'eur_per_eth' => '3000.0'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::SNAPSHOTS as $date => $rates) {
            $snapshot = new ExchangeRateSnapshot();
            $snapshot->setEffectiveAt(new \DateTimeImmutable($date.' 00:00:00'));
            if ($rates['usd_per_eur'] !== null) $snapshot->setUsdPerEur($rates['usd_per_eur']);
            if ($rates['huf_per_eur'] !== null) $snapshot->setHufPerEur($rates['huf_per_eur']);
            if ($rates['uah_per_eur'] !== null) $snapshot->setUahPerEur($rates['uah_per_eur']);
            if ($rates['eur_per_btc'] !== null) $snapshot->setEurPerBtc($rates['eur_per_btc']);
            if ($rates['eur_per_eth'] !== null) $snapshot->setEurPerEth($rates['eur_per_eth']);
            $manager->persist($snapshot);
        }
        $manager->flush();
    }
}
