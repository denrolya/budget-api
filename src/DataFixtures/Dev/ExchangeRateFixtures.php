<?php
namespace App\DataFixtures\Dev;

use App\Entity\ExchangeRateSnapshot;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

final class ExchangeRateFixtures extends Fixture
{
    /**
     * Static snapshot definitions used across dev and test.
     *
     * Keys are dates in YYYY-MM-DD.
     */
    private const SNAPSHOTS = [
        // Very early baseline – covers any old transactions
        '1991-01-01' => [
            'usd_per_eur' => '1.10',
            'huf_per_eur' => '80.0',
            'uah_per_eur' => '5.0',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        '1993-01-01' => [
            'usd_per_eur' => '1.12',
            'huf_per_eur' => '95.0',
            'uah_per_eur' => '5.2',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        '1995-01-01' => [
            'usd_per_eur' => '1.18',
            'huf_per_eur' => '135.0',
            'uah_per_eur' => '5.4',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        '1997-01-01' => [
            'usd_per_eur' => '1.07',
            'huf_per_eur' => '190.0',
            'uah_per_eur' => '5.6',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        // Y2K-ish
        '2000-01-01' => [
            'usd_per_eur' => '0.95',
            'huf_per_eur' => '250.0',
            'uah_per_eur' => '6.0',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        '2003-01-01' => [
            'usd_per_eur' => '1.03',
            'huf_per_eur' => '248.0',
            'uah_per_eur' => '6.5',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        '2005-01-01' => [
            'usd_per_eur' => '1.24',
            'huf_per_eur' => '246.0',
            'uah_per_eur' => '7.0',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        '2008-01-01' => [
            'usd_per_eur' => '1.47',
            'huf_per_eur' => '252.0',
            'uah_per_eur' => '7.8',
            'eur_per_btc' => null,
            'eur_per_eth' => null,
        ],

        // Pre-Euro crisis
        '2010-01-01' => [
            'usd_per_eur' => '1.35',
            'huf_per_eur' => '270.0',
            'uah_per_eur' => '11.0',
            'eur_per_btc' => '100.0',   // 1 BTC = 100 EUR (artificial)
            'eur_per_eth' => null,
        ],

        '2012-01-01' => [
            'usd_per_eur' => '1.29',
            'huf_per_eur' => '288.0',
            'uah_per_eur' => '10.6',
            'eur_per_btc' => '6.0',
            'eur_per_eth' => null,
        ],

        '2014-01-01' => [
            'usd_per_eur' => '1.37',
            'huf_per_eur' => '305.0',
            'uah_per_eur' => '11.5',
            'eur_per_btc' => '550.0',
            'eur_per_eth' => null,
        ],

        '2016-01-01' => [
            'usd_per_eur' => '1.09',
            'huf_per_eur' => '312.0',
            'uah_per_eur' => '27.0',
            'eur_per_btc' => '390.0',
            'eur_per_eth' => '1.0',
        ],

        '2018-01-01' => [
            'usd_per_eur' => '1.20',
            'huf_per_eur' => '309.0',
            'uah_per_eur' => '34.0',
            'eur_per_btc' => '11000.0',
            'eur_per_eth' => '620.0',
        ],

        // Recent-ish
        '2020-01-01' => [
            'usd_per_eur' => '1.12',
            'huf_per_eur' => '330.0',
            'uah_per_eur' => '26.0',
            'eur_per_btc' => '7000.0',
            'eur_per_eth' => '130.0',
        ],

        '2021-01-01' => [
            'usd_per_eur' => '1.22',
            'huf_per_eur' => '360.0',
            'uah_per_eur' => '34.0',
            'eur_per_btc' => '24000.0',
            'eur_per_eth' => '900.0',
        ],

        '2022-01-01' => [
            'usd_per_eur' => '1.13',
            'huf_per_eur' => '370.0',
            'uah_per_eur' => '31.0',
            'eur_per_btc' => '41000.0',
            'eur_per_eth' => '3200.0',
        ],

        '2023-01-01' => [
            'usd_per_eur' => '1.07',
            'huf_per_eur' => '390.0',
            'uah_per_eur' => '39.0',
            'eur_per_btc' => '22000.0',
            'eur_per_eth' => '1500.0',
        ],

        '2023-07-01' => [
            'usd_per_eur' => '1.10',
            'huf_per_eur' => '375.0',
            'uah_per_eur' => '40.0',
            'eur_per_btc' => '28000.0',
            'eur_per_eth' => '1700.0',
        ],

        '2024-01-01' => [
            'usd_per_eur' => '1.09',
            'huf_per_eur' => '383.0',
            'uah_per_eur' => '42.0',
            'eur_per_btc' => '39000.0',
            'eur_per_eth' => '2100.0',
        ],

        '2024-04-01' => [
            'usd_per_eur' => '1.08',
            'huf_per_eur' => '388.0',
            'uah_per_eur' => '42.5',
            'eur_per_btc' => '60000.0',
            'eur_per_eth' => '3050.0',
        ],

        '2024-07-01' => [
            'usd_per_eur' => '1.07',
            'huf_per_eur' => '392.0',
            'uah_per_eur' => '43.0',
            'eur_per_btc' => '54000.0',
            'eur_per_eth' => '2900.0',
        ],

        '2024-10-01' => [
            'usd_per_eur' => '1.06',
            'huf_per_eur' => '396.0',
            'uah_per_eur' => '43.2',
            'eur_per_btc' => '58000.0',
            'eur_per_eth' => '2650.0',
        ],

        '2025-01-01' => [
            'usd_per_eur' => '1.06',
            'huf_per_eur' => '395.0',
            'uah_per_eur' => '43.0',
            'eur_per_btc' => '47000.0',
            'eur_per_eth' => '2800.0',
        ],

        '2025-04-01' => [
            'usd_per_eur' => '1.05',
            'huf_per_eur' => '398.0',
            'uah_per_eur' => '42.7',
            'eur_per_btc' => '65000.0',
            'eur_per_eth' => '3500.0',
        ],

        '2025-07-01' => [
            'usd_per_eur' => '1.05',
            'huf_per_eur' => '401.0',
            'uah_per_eur' => '42.0',
            'eur_per_btc' => '61000.0',
            'eur_per_eth' => '3300.0',
        ],

        '2025-10-01' => [
            'usd_per_eur' => '1.04',
            'huf_per_eur' => '403.0',
            'uah_per_eur' => '41.8',
            'eur_per_btc' => '59000.0',
            'eur_per_eth' => '3100.0',
        ],

        '2026-01-01' => [
            'usd_per_eur' => '1.04',
            'huf_per_eur' => '402.0',
            'uah_per_eur' => '41.0',
            'eur_per_btc' => '52000.0',
            'eur_per_eth' => '3200.0',
        ],

        '2026-01-15' => [
            'usd_per_eur' => '1.05',
            'huf_per_eur' => '401.0',
            'uah_per_eur' => '40.8',
            'eur_per_btc' => '54000.0',
            'eur_per_eth' => '3300.0',
        ],

        '2026-02-01' => [
            'usd_per_eur' => '1.05',
            'huf_per_eur' => '400.5',
            'uah_per_eur' => '40.4',
            'eur_per_btc' => '51000.0',
            'eur_per_eth' => '3050.0',
        ],

        // Your tests use 2026-02-22 as executedAt
        '2026-02-22' => [
            'usd_per_eur' => '1.05',
            'huf_per_eur' => '400.0',
            'uah_per_eur' => '40.0',
            'eur_per_btc' => '50000.0',
            'eur_per_eth' => '3000.0',
        ],

        '2026-03-01' => [
            'usd_per_eur' => '1.04',
            'huf_per_eur' => '402.0',
            'uah_per_eur' => '41.2',
            'eur_per_btc' => '53000.0',
            'eur_per_eth' => '3150.0',
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        $repo = $manager->getRepository(ExchangeRateSnapshot::class);

        foreach (self::SNAPSHOTS as $date => $rates) {
            $effectiveAt = new \DateTimeImmutable($date.' 00:00:00');

            $existing = $repo->findOneBy(['effectiveAt' => $effectiveAt]);
            if ($existing instanceof ExchangeRateSnapshot) {
                continue; // Idempotent: do not create duplicates
            }

            $snapshot = new ExchangeRateSnapshot();
            $snapshot->setEffectiveAt($effectiveAt);

            if ($rates['usd_per_eur'] !== null) {
                $snapshot->setUsdPerEur($rates['usd_per_eur']);
            }
            if ($rates['huf_per_eur'] !== null) {
                $snapshot->setHufPerEur($rates['huf_per_eur']);
            }
            if ($rates['uah_per_eur'] !== null) {
                $snapshot->setUahPerEur($rates['uah_per_eur']);
            }
            if ($rates['eur_per_btc'] !== null) {
                $snapshot->setEurPerBtc($rates['eur_per_btc']);
            }
            if ($rates['eur_per_eth'] !== null) {
                $snapshot->setEurPerEth($rates['eur_per_eth']);
            }

            $manager->persist($snapshot);
        }

        $manager->flush();
    }
}
