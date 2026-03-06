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

        // Y2K-ish
        '2000-01-01' => [
            'usd_per_eur' => '0.95',
            'huf_per_eur' => '250.0',
            'uah_per_eur' => '6.0',
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

        // Recent-ish
        '2020-01-01' => [
            'usd_per_eur' => '1.12',
            'huf_per_eur' => '330.0',
            'uah_per_eur' => '26.0',
            'eur_per_btc' => '7000.0',
            'eur_per_eth' => '130.0',
        ],

        // Your tests use 2026-02-22 as executedAt
        '2026-02-22' => [
            'usd_per_eur' => '1.05',
            'huf_per_eur' => '400.0',
            'uah_per_eur' => '40.0',
            'eur_per_btc' => '50000.0',
            'eur_per_eth' => '3000.0',
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
                $snapshot->setUsdPerEur((string)$rates['usd_per_eur']);
            }
            if ($rates['huf_per_eur'] !== null) {
                $snapshot->setHufPerEur((string)$rates['huf_per_eur']);
            }
            if ($rates['uah_per_eur'] !== null) {
                $snapshot->setUahPerEur((string)$rates['uah_per_eur']);
            }
            if ($rates['eur_per_btc'] !== null) {
                $snapshot->setEurPerBtc((string)$rates['eur_per_btc']);
            }
            if ($rates['eur_per_eth'] !== null) {
                $snapshot->setEurPerEth((string)$rates['eur_per_eth']);
            }

            $manager->persist($snapshot);
        }

        $manager->flush();
    }
}
