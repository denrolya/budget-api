<?php

namespace App\Bank;

use App\Bank\DTO\DraftTransactionData;
use App\Bank\SyncMethod;
use App\Entity\BankCardAccount;
use App\Entity\BankIntegration;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Repository\BankIntegrationRepository;
use App\Service\TransactionCategorizationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates polling-based bank sync for all active integrations.
 *
 * Flow per integration:
 *   1. Find all BankCardAccounts linked to the integration (externalAccountId set + bankIntegration set)
 *   2. Fetch transactions from the PollingCapableInterface provider
 *   3. Deduplicate against existing transactions: same account + same amount + same executedAt (to the minute)
 *   4. Persist draft transactions
 *   5. Update integration.lastSyncedAt
 */
class BankSyncService
{
    public function __construct(
        private readonly BankProviderRegistry $registry,
        private readonly BankIntegrationRepository $integrationRepo,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.bank')]
        private readonly LoggerInterface $logger,
        private readonly TransactionCategorizationService $categorizationService,
    ) {
    }

    /**
     * Sync a single integration by ID. Convenience wrapper for the console command.
     *
     * @throws \InvalidArgumentException if not found
     * @throws \LogicException if provider does not support polling
     */
    public function syncById(int $id, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
    {
        $integration = $this->integrationRepo->find($id);

        if (!$integration) {
            throw new \InvalidArgumentException("BankIntegration #{$id} not found.");
        }

        return $this->sync($integration, $from, $to);
    }

    /**
     * Sync all active integrations that should use polling.
     * An integration is polled when:
     *   - it is active
     *   - its provider implements PollingCapableInterface
     *   - syncMethod is null (auto) OR syncMethod === SyncMethod::Polling
     *
     * @return array<int, int>  map of integration ID → new draft count
     */
    public function syncAll(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $integrations = $this->integrationRepo->findBy(['isActive' => true]);
        $results = [];

        foreach ($integrations as $integration) {
            $provider = $this->registry->get($integration->getProvider());

            if (!$provider instanceof PollingCapableInterface) {
                continue;
            }

            $syncMethod = $integration->getSyncMethod();
            if ($syncMethod !== null && $syncMethod !== SyncMethod::Polling) {
                continue;
            }

            try {
                $results[$integration->getId()] = $this->sync($integration, $from, $to);
            } catch (\Throwable $e) {
                $this->logger->error('[BankSync] syncAll failed for integration #{id}: {msg}', [
                    'id' => $integration->getId(),
                    'msg' => $e->getMessage(),
                ]);
                $results[$integration->getId()] = 0;
            }
        }

        return $results;
    }

    /**
     * Sync a single account for a narrow time window.
     * Called from BankWebhookService immediately after creating a draft,
     * to enrich it with richer data from the polling provider (e.g. merchant name from Activities API).
     */
    public function syncAccount(BankCardAccount $account, DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $integration = $account->getBankIntegration();
        if ($integration === null) {
            return;
        }

        $provider = $this->registry->get($integration->getProvider());
        if (!$provider instanceof PollingCapableInterface) {
            return;
        }

        $externalId = $account->getExternalAccountId();
        if (!$externalId) {
            return;
        }

        $this->categorizationService->resetIndex();
        $this->categorizationService->buildAllIndexes((int) $integration->getOwner()->getId());

        try {
            $items = $provider->fetchTransactions($integration->getCredentials(), $externalId, $from, $to);
        } catch (\Throwable $e) {
            $this->logger->warning('[BankSync] post-webhook enrichment failed for account #{id}: {msg}', [
                'id'  => $account->getId(),
                'msg' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($items as $item) {
            if ($this->isDuplicate($account, $item)) {
                $this->enrichExistingDraft($account, $item);
            } else {
                $this->em->persist($this->buildDraftTransaction($account, $item));
            }
        }

        $this->em->flush();
    }

    /**
     * Sync a single BankIntegration. Returns number of new draft transactions created.
     */
    public function sync(BankIntegration $integration, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
    {
        $provider = $this->registry->get($integration->getProvider());

        if (!$provider instanceof PollingCapableInterface) {
            throw new \LogicException(
                sprintf('Provider "%s" does not support polling.', $integration->getProvider()->value)
            );
        }

        $from ??= $integration->getLastSyncedAt() ?? new DateTimeImmutable('-30 days');
        $to ??= new DateTimeImmutable();

        /** @var BankCardAccount[] $accounts */
        $accounts = $this->em->getRepository(BankCardAccount::class)->findBy([
            'bankIntegration' => $integration,
        ]);

        if (empty($accounts)) {
            $this->logger->info('[BankSync] No linked accounts for integration #{id}', ['id' => $integration->getId()]);

            return 0;
        }

        // Build categorisation index once per sync run (both income and expense, single DB query).
        $this->categorizationService->resetIndex();
        $this->categorizationService->buildAllIndexes((int) $integration->getOwner()->getId());

        $created = 0;

        foreach ($accounts as $account) {
            $externalId = $account->getExternalAccountId();
            if (!$externalId) {
                continue;
            }

            try {
                $items = $provider->fetchTransactions(
                    $integration->getCredentials(),
                    $externalId,
                    $from,
                    $to,
                );
            } catch (\Throwable $e) {
                $ctx = $this->buildAccountLogContext($integration, $account);
                $this->logger->error(
                    '[BankSync] fetchTransactions failed for account #{account_id} (external={external_account_id}, name="{account_name}", currency={currency}, bank="{bank}", provider={provider}, integration={integration_id}): {msg}',
                    $ctx + ['msg' => $e->getMessage()],
                );
                continue;
            }

            foreach ($items as $item) {
                if ($this->isDuplicate($account, $item)) {
                    // Duplicate exists — try to enrich if incoming note is better.
                    $this->enrichExistingDraft($account, $item);
                    continue;
                }

                $transaction = $this->buildDraftTransaction($account, $item);
                $this->em->persist($transaction);
                ++$created;
            }
        }

        $integration->markSyncedNow();
        $this->em->flush();

        $this->logger->info('[BankSync] Sync complete for integration #{id}: {n} new drafts', [
            'id' => $integration->getId(),
            'n' => $created,
        ]);

        return $created;
    }

    /**
     * @return array<string, scalar>
     */
    private function buildAccountLogContext(BankIntegration $integration, BankCardAccount $account): array
    {
        return [
            'integration_id' => $integration->getId(),
            'provider' => $integration->getProvider()->value,
            'account_id' => $account->getId(),
            'external_account_id' => $account->getExternalAccountId() ?? 'n/a',
            'account_name' => $account->getName() ?? 'n/a',
            'currency' => $account->getCurrency(),
            'bank' => $account->getBankName() ?? 'n/a',
        ];
    }

    /**
     * Legacy generic notes that carry no real information.
     * Shared with BankWebhookService — if an existing draft has one of these,
     * it can be enriched with real data from a richer source.
     */
    private const GENERIC_NOTES = [
        '',
        'Card payment',
        'Online purchase',
        'Cash withdrawal',
        'Cash advance',
        'Card transaction',
        'Card refund',
        'Chargeback',
        'Balance transfer',
        'Currency conversion',
        'International transfer',
        'SEPA transfer',
        'Direct debit',
        'Wise transaction',
        'Transfer received',
    ];

    private function isDuplicate(BankCardAccount $account, DraftTransactionData $data): bool
    {
        return $this->findDuplicate($account, $data) !== null;
    }

    private function findDuplicate(BankCardAccount $account, DraftTransactionData $data): ?Transaction
    {
        $minuteStr = $data->executedAt->format('Y-m-d H:i');

        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(Transaction::class, 't')
            ->where('t.account = :account')
            ->andWhere('t.amount = :amount')
            ->andWhere('DATE_FORMAT(t.executedAt, \'%Y-%m-%d %H:%i\') = :ts')
            ->setParameter('account', $account)
            ->setParameter('amount', abs($data->amount))
            ->setParameter('ts', $minuteStr)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * When a duplicate exists and the incoming data has a richer note,
     * update the existing draft's note and re-categorize.
     */
    private function enrichExistingDraft(BankCardAccount $account, DraftTransactionData $data): void
    {
        if (trim($data->note) === '') {
            return;
        }

        $existing = $this->findDuplicate($account, $data);
        if ($existing === null) {
            return;
        }

        try {
            $existingNote = trim($existing->getNote() ?? '');
        } catch (\Error) {
            $existingNote = '';
        }

        if ($existingNote !== '' && !in_array($existingNote, self::GENERIC_NOTES, true)) {
            return;
        }

        $isIncome       = $data->amount > 0;
        $categorization = $this->categorizationService->suggest($data->note, $isIncome);

        $existing->setNote($categorization->note);

        if ($isIncome) {
            $category = $this->em->getRepository(IncomeCategory::class)->find($categorization->categoryId);
        } else {
            $category = $this->em->getRepository(ExpenseCategory::class)->find($categorization->categoryId);
        }

        if ($category !== null) {
            $existing->setCategory($category);
        }
    }

    private function buildDraftTransaction(BankCardAccount $account, DraftTransactionData $data): Transaction
    {
        $isIncome = $data->amount > 0;
        $owner    = $account->getOwner();

        $categorization = $this->categorizationService->suggest($data->note, $isIncome);

        if ($isIncome) {
            $category = $this->em->getRepository(IncomeCategory::class)->find($categorization->categoryId)
                ?? $this->em->getRepository(IncomeCategory::class)->find(Category::INCOME_CATEGORY_ID_UNKNOWN);
            $transaction = new Income(true);
        } else {
            $category = $this->em->getRepository(ExpenseCategory::class)->find($categorization->categoryId)
                ?? $this->em->getRepository(ExpenseCategory::class)->find(Category::EXPENSE_CATEGORY_ID_UNKNOWN);
            $transaction = new Expense(true);
        }

        $transaction
            ->setAccount($account)
            ->setAmount(abs($data->amount))
            ->setCategory($category)
            ->setNote($categorization->note)
            ->setExecutedAt($data->executedAt)
            ->setOwner($owner);

        return $transaction;
    }
}
