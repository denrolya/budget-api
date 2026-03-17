<?php

declare(strict_types=1);

namespace App\Command;

use App\Bank\BankProvider;
use App\Bank\BankWebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Simulates a Wise webhook event by pushing a sample payload directly
 * through BankWebhookService — tests the full business logic pipeline
 * without needing a real Wise event.
 *
 * Usage:
 *   php bin/console app:wise:test-webhook --balance-id=123456 --amount=10.00 --currency=EUR
 *   php bin/console app:wise:test-webhook --balance-id=123456 --amount=9.60  --currency=EUR --type=debit
 *   php bin/console app:wise:test-webhook --balance-id=123456 --amount=1.00  --currency=EUR --schema=credit-v3
 */
#[AsCommand(name: 'app:wise:test-webhook', description: 'Simulate a Wise webhook event through the full pipeline (no real HTTP call).')]
class WiseTestWebhookCommand extends Command
{
    public function __construct(
        private readonly BankWebhookService $webhookService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('balance-id', null, InputOption::VALUE_REQUIRED, 'Wise balance ID (= BankCardAccount.externalAccountId)', '0')
            ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Transaction amount (always positive)', '1.00')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Currency code', 'EUR')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Transaction type: credit or debit', 'credit')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Payload schema: update-v3 (default), credit-v2, credit-v3', 'update-v3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawBalanceId = $input->getOption('balance-id');
        assert(\is_string($rawBalanceId));
        $balanceId = $rawBalanceId;
        $rawAmount = $input->getOption('amount');
        assert(is_numeric($rawAmount));
        $amount = (float) $rawAmount;
        $rawCurrency = $input->getOption('currency');
        assert(\is_string($rawCurrency));
        $currency = strtoupper($rawCurrency);
        $rawType = $input->getOption('type');
        assert(\is_string($rawType));
        $type = strtolower($rawType);
        $rawSchema = $input->getOption('schema');
        assert(\is_string($rawSchema));
        $schema = strtolower($rawSchema);

        if (!\in_array($type, ['credit', 'debit'], true)) {
            $io->error('--type must be "credit" or "debit"');

            return Command::FAILURE;
        }

        $payload = match ($schema) {
            'credit-v3' => $this->buildCreditV3Payload($balanceId, $amount, $currency),
            'credit-v2' => $this->buildFlatPayload('balances#credit', $balanceId, $amount, $currency, $type),
            default => $this->buildFlatPayload('balances#update', $balanceId, $amount, $currency, $type),
        };

        $io->section('Sending payload');
        $io->writeln(json_encode($payload, \JSON_PRETTY_PRINT));

        try {
            $transaction = $this->webhookService->handle(BankProvider::Wise, $payload);
        } catch (Throwable $e) {
            $io->error('Pipeline error: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if (null === $transaction) {
            $io->warning(
                'BankWebhookService returned null. Possible reasons:' . \PHP_EOL
                . '  • No BankCardAccount with externalAccountId="' . $balanceId . '" found in DB' . \PHP_EOL
                . '  • Duplicate transaction (same amount + minute already exists)' . \PHP_EOL
                . '  • Payload parsed as non-transaction event',
            );

            return Command::FAILURE;
        }

        $executedAt = $transaction->getExecutedAt();
        \assert(null !== $executedAt);

        $io->success(\sprintf(
            'Transaction #%d created: %s %.2f %s at %s',
            $transaction->getId(),
            $type,
            $amount,
            $currency,
            $executedAt->format('Y-m-d H:i:s'),
        ));

        return Command::SUCCESS;
    }

    /** balances#update v3.0.0 (and v2.0.0) flat structure — the primary format */
    private function buildFlatPayload(string $eventType, string $balanceId, float $amount, string $currency, string $type): array
    {
        return [
            'event_type' => $eventType,
            'schema_version' => '3.0.0',
            'subscription_id' => 'test-00000000-0000-0000-0000-000000000001',
            'sent_at' => date('c'),
            'data' => [
                'resource' => ['id' => (int) $balanceId, 'profile_id' => 0, 'type' => 'balance-account'],
                'amount' => $amount,
                'balance_id' => (int) $balanceId,
                'channel_name' => 'MANUAL_TEST',
                'currency' => $currency,
                'occurred_at' => date('Y-m-d\TH:i:s\Z'),
                'transaction_type' => $type,
                'transfer_reference' => 'test-' . date('Ymd-His'),
            ],
        ];
    }

    /** balances#credit v3.0.0 action/resource structure */
    private function buildCreditV3Payload(string $balanceId, float $amount, string $currency): array
    {
        return [
            'event_type' => 'balances#credit',
            'schema_version' => '3.0.0',
            'subscription_id' => 'test-00000000-0000-0000-0000-000000000002',
            'sent_at' => date('c'),
            'data' => [
                'action' => [
                    'type' => 'credit',
                    'id' => 99999,
                    'profile_id' => 0,
                    'account_id' => (int) $balanceId,
                ],
                'resource' => [
                    'id' => 'test-transfer-id',
                    'reference' => 'TEST/REF/' . date('Ymd'),
                    'settled_amount' => ['value' => $amount, 'currency' => $currency],
                    'instructed_amount' => ['value' => $amount, 'currency' => $currency],
                ],
            ],
        ];
    }
}
