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

        $this->logger->info('[BankWebhook] Raw payload from {bank}: event={event} payload={payload}', [
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

        return $transaction;
    }

    private function isDuplicate(BankCardAccount $account, DraftTransactionData $data): bool
    {
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
