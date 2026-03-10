<?php

namespace App\Command;

use App\Bank\BankProvider;
use App\Bank\BankWebhookRegistrationService;
use App\Entity\BankIntegration;
use App\Repository\BankIntegrationRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:bank:webhooks:refresh', description: 'Re-register webhooks for bank integrations (deploy-safe self-healing).')]
class BankWebhooksRefreshCommand extends Command
{
    public function __construct(
        private readonly BankIntegrationRepository $integrationRepository,
        private readonly BankWebhookRegistrationService $registrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('integration', 'i', InputOption::VALUE_REQUIRED, 'Refresh only this BankIntegration ID')
            ->addOption('provider', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter by provider (wise, monobank). Can be passed multiple times.')
            ->addOption('include-inactive', null, InputOption::VALUE_NONE, 'Include inactive integrations')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be refreshed without calling provider APIs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $integrationId = $input->getOption('integration');
        $providerFilter = $input->getOption('provider');
        $includeInactive = (bool) $input->getOption('include-inactive');
        $dryRun = (bool) $input->getOption('dry-run');

        $allowedProviders = [];
        foreach ($providerFilter as $rawProvider) {
            try {
                $allowedProviders[] = BankProvider::from((string) $rawProvider);
            } catch (\ValueError) {
                $io->error(sprintf(
                    'Unknown provider "%s". Allowed: %s',
                    $rawProvider,
                    implode(', ', array_map(static fn(BankProvider $provider) => $provider->value, BankProvider::cases())),
                ));

                return Command::FAILURE;
            }
        }

        if ($integrationId !== null) {
            $integration = $this->integrationRepository->find((int) $integrationId);
            if ($integration === null) {
                $io->error(sprintf('BankIntegration #%d not found.', (int) $integrationId));

                return Command::FAILURE;
            }

            $integrations = [$integration];
        } else {
            $criteria = $includeInactive ? [] : ['isActive' => true];
            $integrations = $this->integrationRepository->findBy($criteria, ['id' => 'ASC']);
        }

        if (!empty($allowedProviders)) {
            $integrations = array_values(array_filter(
                $integrations,
                static fn($integration) => in_array($integration->getProvider(), $allowedProviders, true),
            ));
        }

        if (empty($integrations)) {
            $io->info('No matching integrations found.');

            return Command::SUCCESS;
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $processedByFingerprint = [];

        foreach ($integrations as $integration) {
            $label = sprintf('#%d (%s)', $integration->getId(), $integration->getProvider()->value);

            $fingerprint = $this->buildCredentialFingerprint($integration);
            if (isset($processedByFingerprint[$fingerprint])) {
                $io->writeln(sprintf(
                    '<comment>SKIP</comment> %s: duplicate provider credentials (already handled by integration #%d)',
                    $label,
                    $processedByFingerprint[$fingerprint],
                ));
                ++$skipped;
                continue;
            }

            if (!$this->registrationService->supports($integration)) {
                $io->writeln(sprintf('<comment>SKIP</comment> %s: provider has no webhook support', $label));
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('<info>DRY</info>  %s: would refresh webhook registration', $label));
                $processedByFingerprint[$fingerprint] = $integration->getId();
                ++$ok;
                continue;
            }

            try {
                $webhookUrl = $this->registrationService->register($integration);
                $io->writeln(sprintf('<info>OK</info>   %s: %s', $label, $webhookUrl));
                $processedByFingerprint[$fingerprint] = $integration->getId();
                ++$ok;
            } catch (\LogicException $e) {
                $io->writeln(sprintf('<comment>SKIP</comment> %s: %s', $label, $e->getMessage()));
                $processedByFingerprint[$fingerprint] = $integration->getId();
                ++$skipped;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<error>FAIL</error> %s: %s', $label, $e->getMessage()));
                ++$failed;
            }
        }

        $io->newLine();
        $io->writeln(sprintf('Summary: ok=%d, skipped=%d, failed=%d', $ok, $skipped, $failed));

        if ($failed > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function buildCredentialFingerprint(BankIntegration $integration): string
    {
        $provider = $integration->getProvider()->value;
        $credentials = $integration->getCredentials();

        try {
            $encoded = json_encode($credentials, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $encoded = serialize($credentials);
        }

        return hash('sha256', $provider . '|' . (string) $encoded);
    }
}
