<?php

namespace App\Command;

use App\Bank\BankProvider;
use App\Entity\BankCardAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Diagnoses the Wise integration state:
 *   - API connectivity and profile ID
 *   - Balance accounts vs BankCardAccount records
 *   - Webhook subscription status
 *   - Clear next-step instructions
 */
#[AsCommand(name: 'app:wise:diagnose', description: 'Diagnose Wise API connectivity and webhook subscription status.')]
class WiseDiagnoseCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $wiseClient,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Wise Integration Diagnostics');

        // ── 1. Connectivity / Profiles ────────────────────────────────────────
        $io->section('1. API connectivity — GET /v2/profiles');
        try {
            $response = $this->wiseClient->request('GET', '/v2/profiles');
            $profiles = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $io->error('Cannot reach Wise API: ' . $e->getMessage());
            $io->warning('Check WISE_API_KEY and WISE_BASE_URL in your .env/.env.production.');

            return Command::FAILURE;
        }

        $profileId = null;
        foreach ($profiles as $profile) {
            $type = strtolower($profile['type'] ?? '');
            $id   = $profile['id'] ?? '?';
            $io->writeln(sprintf('  Profile #%s type=%s', $id, $type));
            if ($type === 'personal' && $profileId === null) {
                $profileId = (int) $id;
            }
        }

        if ($profileId === null) {
            $io->error('No personal profile found. Check the API key belongs to the right account.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Connected. Personal profile ID: %d', $profileId));

        // ── 2. Balance accounts ───────────────────────────────────────────────
        $io->section('2. Wise balance accounts — GET /v4/profiles/{id}/balances');
        try {
            $response = $this->wiseClient->request('GET', "/v4/profiles/{$profileId}/balances?types=STANDARD");
            $balances = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $io->warning('Could not fetch balances: ' . $e->getMessage());
            $balances = [];
        }

        $wiseBalanceIds = [];
        foreach ($balances as $balance) {
            $currency = $balance['totalWorth']['currency'] ?? $balance['amount']['currency'] ?? '?';
            $amount   = (float) ($balance['totalWorth']['value'] ?? $balance['amount']['value'] ?? 0);
            $id       = $balance['id'] ?? '?';
            $io->writeln(sprintf('  Balance #%s %s %.2f', $id, $currency, $amount));
            $wiseBalanceIds[] = (string) $id;
        }

        // Check which are linked in DB
        $io->section('3. BankCardAccount records for Wise');
        $accounts = $this->em->getRepository(BankCardAccount::class)->findAll();
        $linkedIds = [];
        foreach ($accounts as $account) {
            $integration = $account->getBankIntegration();
            if ($integration === null || $integration->getProvider() !== BankProvider::Wise) {
                continue;
            }
            $extId = $account->getExternalAccountId();
            $io->writeln(sprintf('  DB account #%d externalId=%s currency=%s', $account->getId(), $extId, $account->getCurrency()));
            $linkedIds[] = $extId;
        }

        $unlinked = array_diff($wiseBalanceIds, $linkedIds);
        if (!empty($unlinked)) {
            $io->warning(sprintf(
                'Wise balances not linked to any BankCardAccount: %s. '
                . 'Run account sync (fetchAccounts) to import them.',
                implode(', ', $unlinked),
            ));
        } elseif (!empty($wiseBalanceIds)) {
            $io->success('All Wise balances are linked to BankCardAccount records.');
        }

        // ── 3. Webhook subscriptions ──────────────────────────────────────────
        $io->section(sprintf('4. Webhook subscriptions — GET /v3/profiles/%d/subscriptions', $profileId));
        try {
            $response      = $this->wiseClient->request('GET', "/v3/profiles/{$profileId}/subscriptions");
            $subscriptions = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            if (empty($subscriptions)) {
                $io->warning('No webhook subscriptions found for this profile.');
            } else {
                foreach ($subscriptions as $sub) {
                    $event   = $sub['trigger_on'] ?? '?';
                    $url     = $sub['delivery']['url'] ?? '?';
                    $version = $sub['delivery']['version'] ?? '?';
                    $subId   = $sub['id'] ?? '?';
                    $io->writeln(sprintf('  %-30s schema=%-5s id=%s', $event, $version, $subId));
                    $io->writeln(sprintf('  %s→ %s', str_repeat(' ', 32), $url));
                }
            }

            $hasUpdate = false;
            $hasCredit = false;
            foreach ($subscriptions as $sub) {
                $event = $sub['trigger_on'] ?? '';
                if ($event === 'balances#update') {
                    $hasUpdate = true;
                }
                if ($event === 'balances#credit') {
                    $hasCredit = true;
                }
            }

            if ($hasUpdate) {
                $io->success('balances#update subscription found — full debit+credit coverage active.');
            } elseif ($hasCredit) {
                $io->note('balances#credit subscription found — credits only (no debit coverage).');
            } else {
                $io->warning('No balance webhook subscription found. See next steps below.');
            }
        } catch (HttpExceptionInterface $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($status === 403) {
                $io->error(sprintf(
                    'GET /v3/profiles/%d/subscriptions → 403 Forbidden. '
                    . 'The current API token lacks "Create webhooks" permission.',
                    $profileId,
                ));

                $io->section('How to fix (Option A — recommended):');
                $io->listing([
                    'Go to https://wise.com/settings/api-tokens',
                    'Create a NEW token with "Full access" OR ensure "Webhooks" permission is ticked',
                    'Update WISE_API_KEY in .env.production and redeploy',
                    'Then run: php bin/console app:bank:webhooks:refresh --provider=wise',
                ]);

                $io->section('How to fix (Option B — manual, credits only):');
                $io->listing([
                    'Go to https://wise.com/settings/developer-tools/webhooks (or Settings → Webhooks)',
                    'Click "Add webhook"',
                    sprintf('URL: https://dasfas.xyz/api/webhooks/wise'),
                    'Event: "Account deposit events" (= balances#credit)',
                    'Save — Wise will send a test ping to the URL',
                    'This covers INCOMING money only (not card spending / debits)',
                ]);
            } else {
                $io->warning(sprintf('Subscriptions API returned %d: %s', $status, $e->getMessage()));
            }
        } catch (\Throwable $e) {
            $io->warning('Could not check subscriptions: ' . $e->getMessage());
        }

        // ── 4. Summary ────────────────────────────────────────────────────────
        $io->section('5. Quick test');
        $io->text([
            'Once a subscription is active, run a test:',
            sprintf('  php bin/console app:wise:test-webhook --balance-id=<ID> --amount=1.00 --currency=EUR'),
            '',
            'Or make a small real transaction in Wise and watch the logs:',
            '  tail -f var/log/prod.log | grep BankWebhook',
        ]);

        return Command::SUCCESS;
    }
}
