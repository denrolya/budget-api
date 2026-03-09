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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
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

        $from ??= new DateTimeImmutable('-30 days');
        $to ??= new DateTimeImmutable();

        /** @var BankCardAccount[] $accounts */
        $accounts = $this->em->getRepository(BankCardAccount::class)->findBy([
            'bankIntegration' => $integration,
        ]);

        if (empty($accounts)) {
            $this->logger->info('[BankSync] No linked accounts for integration #{id}', ['id' => $integration->getId()]);

            return 0;
        }

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
                $this->logger->error('[BankSync] fetchTransactions failed for account #{id}: {msg}', [
                    'id' => $account->getId(),
                    'msg' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($items as $item) {
                if ($this->isDuplicate($account, $item)) {
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

    private function isDuplicate(BankCardAccount $account, DraftTransactionData $data): bool
    {
        // Round executedAt to minute precision for comparison
        $minuteStr = $data->executedAt->format('Y-m-d H:i');

        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Transaction::class, 't')
            ->where('t.account = :account')
            ->andWhere('t.amount = :amount')
            ->andWhere('DATE_FORMAT(t.executedAt, \'%Y-%m-%d %H:%i\') = :ts')
            ->setParameter('account', $account)
            ->setParameter('amount', abs($data->amount))
            ->setParameter('ts', $minuteStr);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    private function buildDraftTransaction(BankCardAccount $account, DraftTransactionData $data): Transaction
    {
        $isIncome = $data->amount > 0;
        $owner = $account->getOwner();

        if ($isIncome) {
            $category = $this->em->getRepository(IncomeCategory::class)
                ->find(Category::INCOME_CATEGORY_ID_UNKNOWN);
            $transaction = new Income(true);
        } else {
            $category = $this->em->getRepository(ExpenseCategory::class)
                ->find(Category::EXPENSE_CATEGORY_ID_UNKNOWN);
            $transaction = new Expense(true);
        }

        $transaction
            ->setAccount($account)
            ->setAmount(abs($data->amount))
            ->setCategory($category)
            ->setNote($data->note)
            ->setExecutedAt($data->executedAt)
            ->setOwner($owner);

        return $transaction;
    }
}
