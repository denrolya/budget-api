<?php

namespace App\Bank;

use App\Bank\DTO\DraftTransactionData;
use App\Entity\BankCardAccount;
use App\Entity\Category;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\Transaction;
use App\Entity\User;
use App\Service\PushNotificationService;
use App\Service\TransactionCategorizationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Handles incoming bank webhooks.
 *
 * Flow:
 *   1. Identify the bank provider from the URL segment (passed in from the controller)
 *   2. Delegate payload parsing to the WebhookCapableInterface provider
 *   3. Find the BankCardAccount by externalAccountId
 *   4. Deduplicate
 *   5. Persist a draft transaction
 */
class BankWebhookService
{
    public function __construct(
        private readonly BankProviderRegistry $registry,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.bank')]
        private readonly LoggerInterface $logger,
        private readonly TransactionCategorizationService $categorizationService,
        private readonly BankSyncService $syncService,
        private readonly PushNotificationService $pushService,
    ) {
    }

    /**
     * Process a raw webhook payload for the given bank.
     * Returns the created Transaction, or null if the payload was a non-transaction event or a duplicate.
     */
    public function handle(BankProvider $bank, array $payload): ?Transaction
    {
        $provider = $this->registry->get($bank);

        if (!$provider instanceof WebhookCapableInterface) {
            throw new \LogicException(sprintf('Provider "%s" does not support webhooks.', $bank->value));
        }

        $eventType = (string) ($payload['event_type'] ?? 'unknown');

        $this->logger->debug('[BankWebhook] Raw payload from {bank}: event={event} payload={payload}', [
            'bank'    => $bank->value,
            'event'   => $eventType,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $data = $provider->parseWebhookPayload($payload);

        if ($data === null) {
            $this->logger->info('[BankWebhook] Payload yielded no transaction (event={event}, bank={bank}) — see Wise logs above for reason.', [
                'event' => $eventType,
                'bank'  => $bank->value,
            ]);

            return null;
        }

        $this->logger->info('[BankWebhook] Parsed: external_account={ext} amount={amt} currency={cur} note="{note}"', [
            'ext'  => $data->externalAccountId,
            'amt'  => $data->amount,
            'cur'  => $data->currency ?? 'n/a',
            'note' => $data->note,
        ]);

        $account = $this->em->getRepository(BankCardAccount::class)->findOneBy([
            'externalAccountId' => $data->externalAccountId,
        ]);

        if ($account === null) {
            $this->logger->warning('[BankWebhook] No BankCardAccount found for externalAccountId={id} — is this balance linked in the app?', [
                'id' => $data->externalAccountId,
            ]);

            return null;
        }

        if ($this->isDuplicate($account, $data)) {
            // Try enrichment: if incoming note has real data and existing draft is empty/generic, update it.
            $enriched = $this->enrichExistingDraft($account, $data);
            if ($enriched !== null) {
                $this->logger->info('[BankWebhook] Draft #{tx_id} enriched: note="{note}" category="{cat}"', [
                    'tx_id' => $enriched->getId(),
                    'note'  => $enriched->getNote(),
                    'cat'   => $enriched->getCategory()?->getName() ?? 'none',
                ]);

                return $enriched;
            }

            $this->logger->info('[BankWebhook] Duplicate skipped: account=#{id} amount={amt} at {ts}', [
                'id'  => $account->getId(),
                'amt' => abs($data->amount),
                'ts'  => $data->executedAt->format('Y-m-d H:i'),
            ]);

            return null;
        }

        $owner = $account->getOwner();
        assert($owner instanceof User);
        $this->categorizationService->resetIndex();
        $this->categorizationService->buildAllIndexes($owner->getId());

        $transaction = $this->buildDraftTransaction($account, $data);
        $this->em->persist($transaction);
        $this->em->flush();

        $this->logger->info('[BankWebhook] Transaction #{tx_id} created: {type} {amount} {currency} account=#{account_id} | raw_note="{raw_note}" saved_note="{saved_note}" category="{category}"', [
            'tx_id'      => $transaction->getId(),
            'type'       => $data->amount >= 0 ? 'credit' : 'debit',
            'amount'     => abs($data->amount),
            'currency'   => $data->currency ?? 'n/a',
            'account_id' => $account->getId(),
            'raw_note'   => $data->note,
            'saved_note' => $transaction->getNote(),
            'category'   => $transaction->getCategory()?->getName() ?? 'none',
        ]);

        // Immediately enrich the draft with Activities API data (merchant name, exchange rate, etc.).
        // Uses a ±2-minute window around the transaction time so we fetch only this one transaction.
        $this->syncService->syncAccount(
            $account,
            $data->executedAt->modify('-2 minutes'),
            $data->executedAt->modify('+2 minutes'),
        );

        $this->logger->info('[BankWebhook] Post-webhook enrichment done: note="{note}" category="{cat}"', [
            'note' => $transaction->getNote() ?? '',
            'cat'  => $transaction->getCategory()?->getName() ?? 'none',
        ]);

        $this->notifyTransaction($transaction, $owner, $account);

        return $transaction;
    }

    private function isDuplicate(BankCardAccount $account, DraftTransactionData $data): bool
    {
        return $this->findDuplicate($account, $data) !== null;
    }

    /**
     * Find the existing transaction that matches (account + amount + executedAt to minute).
     */
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
     * Legacy generic notes produced by older versions of WiseProvider.
     * If an existing draft has one of these notes, it can be enriched with real data.
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

    /**
     * When a duplicate is detected and the incoming note has real data,
     * update the existing draft's note and re-categorize.
     */
    private function enrichExistingDraft(BankCardAccount $account, DraftTransactionData $data): ?Transaction
    {
        // No enrichment possible if incoming note is empty
        if (trim($data->note) === '') {
            return null;
        }

        $existing = $this->findDuplicate($account, $data);
        if ($existing === null) {
            return null;
        }

        try {
            $existingNote = trim($existing->getNote() ?? '');
        } catch (\Error) {
            $existingNote = ''; // uninitialized property
        }

        // Only enrich if existing note is empty or a generic label
        if ($existingNote !== '' && !in_array($existingNote, self::GENERIC_NOTES, true)) {
            return null;
        }

        // Re-categorize with the richer note
        $owner = $account->getOwner();
        assert($owner instanceof User);
        $this->categorizationService->resetIndex();
        $this->categorizationService->buildAllIndexes($owner->getId());

        $isIncome       = $data->amount > 0;
        $categorization = $this->categorizationService->suggest($data->note, $isIncome);

        $existing->setNote($categorization->note);

        // Update category if categorization found a better match
        if ($isIncome) {
            $category = $this->em->getRepository(IncomeCategory::class)->find($categorization->categoryId);
        } else {
            $category = $this->em->getRepository(ExpenseCategory::class)->find($categorization->categoryId);
        }

        if ($category !== null) {
            $existing->setCategory($category);
        }

        $this->em->flush();

        return $existing;
    }

    private function notifyTransaction(Transaction $transaction, User $owner, BankCardAccount $account): void
    {
        try {
            $isExpense = $transaction instanceof Expense;
            $amount    = $transaction->getAmount();
            $currency  = $account->getCurrency();
            $note      = $transaction->getNote() ?? '';
            $category  = $transaction->getCategory()?->getName() ?? '';

            $sign = $isExpense ? '−' : '+';
            $body = trim(sprintf('%s%s %s · %s', $sign, number_format($amount, 2), $currency, $note ?: $category));

            $this->pushService->sendToUser($owner, [
                'title' => $account->getName(),
                'body'  => $body,
                'url'   => '/m/ledger',
                'tag'   => 'tx-'.$account->getId(),
            ]);
        } catch (\Throwable $e) {
            // Never let a push failure break the webhook flow
            $this->logger->warning('[BankWebhook] Push notification failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    private function buildDraftTransaction(BankCardAccount $account, DraftTransactionData $data): Transaction
    {
        $isIncome = $data->amount > 0;
        $owner    = $account->getOwner();

        // Index is built lazily inside suggest() on the first call per request.
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
