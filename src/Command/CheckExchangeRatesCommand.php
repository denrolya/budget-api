<?php

namespace App\Command;

use App\Service\FixerService;
use App\Service\PushNotificationService;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fetches current exchange rates and sends push notifications when a pair moves
 * beyond its configured threshold since the last check.
 *
 * Intended to be triggered by a cron job:
 *   * /15 * * * *  php /var/www/api/bin/console app:check-exchange-rates
 */
#[AsCommand(
    name: 'app:check-exchange-rates',
    description: 'Check exchange rates for significant movements and push-notify subscribers.',
)]
class CheckExchangeRatesCommand extends Command
{
    /**
     * Minimum percentage change required to trigger a notification.
     * Keyed by the non-base currency; base currency is EUR (or the app default).
     */
    private const THRESHOLDS = [
        'USD' => 1.0,   // 1 % move on EUR/USD
        'HUF' => 1.5,   // 1.5 % — more volatile
        'UAH' => 2.0,   // 2 %   — very volatile
        'BTC' => 5.0,   // 5 %   — crypto normal noise
        'ETH' => 5.0,
    ];

    private const CACHE_KEY = 'push.rate_snapshot';

    public function __construct(
        private readonly FixerService $fixerService,
        private readonly PushNotificationService $pushService,
        private readonly CacheInterface $cache,
        private readonly string $baseCurrency,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $currentRates = $this->fixerService->getLatest();
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to fetch rates: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        // Load previous snapshot (null on first run — just seed it)
        $previousRates = $this->loadSnapshot();

        if ($previousRates === null) {
            $this->saveSnapshot($currentRates);
            $output->writeln('First run — rate snapshot seeded, no comparison yet.');
            return Command::SUCCESS;
        }

        foreach (self::THRESHOLDS as $currency => $threshold) {
            $prev    = $previousRates[$currency] ?? null;
            $current = $currentRates[$currency] ?? null;

            if ($prev === null || $current === null || $prev == 0.0) {
                continue;
            }

            $changePct = abs(($current - $prev) / $prev) * 100;

            if ($changePct < $threshold) {
                continue;
            }

            $direction = $current > $prev ? '▲' : '▼';
            $sign      = $current > $prev ? '+' : '-';

            $output->writeln(sprintf(
                '%s/%s moved %s%.2f%% (%.4f → %.4f) — sending push',
                $this->baseCurrency, $currency, $sign, $changePct, $prev, $current
            ));

            $this->pushService->broadcast([
                'title' => sprintf('%s/%s %s %.2f%%', $this->baseCurrency, $currency, $direction, $changePct),
                'body'  => sprintf('%.4f → %.4f', $prev, $current),
                'url'   => '/m/rates',
                'tag'   => 'rates-'.$currency,
            ]);
        }

        $this->saveSnapshot($currentRates);
        $output->writeln('Rate snapshot updated.');

        return Command::SUCCESS;
    }

    private function loadSnapshot(): ?array
    {
        try {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?array {
                // Return null so we can detect first-run without throwing
                $item->expiresAfter(86400 * 7); // 7 days
                return null;
            });
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function saveSnapshot(array $rates): void
    {
        try {
            // Delete existing item so we can write a fresh value
            $this->cache->delete(self::CACHE_KEY);
            $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($rates): array {
                $item->expiresAfter(86400 * 7);
                return $rates;
            });
        } catch (InvalidArgumentException) {
            // Non-fatal
        }
    }
}
