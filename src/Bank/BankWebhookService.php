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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
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

        $data = $provider->parseWebhookPayload($payload);

        if ($data === null) {
            $this->logger->info('[BankWebhook] Non-transaction payload from {bank}, skipped.', ['bank' => $bank->value]);

            return null;
        }

        $account = $this->em->getRepository(BankCardAccount::class)->findOneBy([
            'externalAccountId' => $data->externalAccountId,
        ]);

        if ($account === null) {
            $this->logger->warning('[BankWebhook] No account found for externalAccountId {id}', [
                'id' => $data->externalAccountId,
            ]);

            return null;
        }

        if ($this->isDuplicate($account, $data)) {
            $this->logger->info('[BankWebhook] Duplicate transaction skipped for account #{id}', [
                'id' => $account->getId(),
            ]);

            return null;
        }

        $transaction = $this->buildDraftTransaction($account, $data);
        $this->em->persist($transaction);
        $this->em->flush();

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
