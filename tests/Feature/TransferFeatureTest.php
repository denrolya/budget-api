<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transfer;
use App\Tests\BaseApiTestCase;

class TransferFeatureTest extends BaseApiTestCase
{
    public function testCreateTransferCreatesTransactions(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '2',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        self::assertEquals(100.0, $fromExpense->getAmount());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());
        self::assertEquals($eurBalanceBefore - 100, (float) $fromExpense->getAccount()->getBalance());
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        self::assertEquals(200.0, $toIncome->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $toIncome->getAccount()->getId());
        self::assertEquals($uahBalanceBefore + 200, (float) $toIncome->getAccount()->getBalance());
    }

    public function testDeleteTransferExpenseDoesNotDeletesRelatedTransferAndUpdatesAccountBalances(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        $expenseId = $fromExpense->getId();
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        $incomeId = $toIncome->getId();
        self::assertEquals($eurBalanceBefore - 100, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, (float) $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());

        $this->entityManager()->remove($fromExpense);
        $this->entityManager()->flush();
        $reloadedEurAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $reloadedUahAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($reloadedEurAccount);
        self::assertNotNull($reloadedUahAccount);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        $fromExpenseReloaded = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncomeReloaded = $this->entityManager()->getRepository(Income::class)->find($incomeId);
        self::assertNull($transfer);
        self::assertNull($fromExpenseReloaded);
        self::assertNull($toIncomeReloaded);
        self::assertEquals($eurBalanceBefore, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, (float) $this->accountCashUAH->getBalance());
    }

    public function testDeleteTransferIncomesDoesNotDeletesRelatedTransferAndUpdatesAccountBalances(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        $expenseId = $fromExpense->getId();
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        $incomeId = $toIncome->getId();
        self::assertEquals($eurBalanceBefore - 100, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, (float) $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());

        $this->entityManager()->remove($toIncome);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        $fromExpenseReloaded = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncomeReloaded = $this->entityManager()->getRepository(Income::class)->find($incomeId);
        self::assertNull($transfer);
        self::assertNull($fromExpenseReloaded);
        self::assertNull($toIncomeReloaded);
        self::assertEquals($eurBalanceBefore, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, (float) $this->accountCashUAH->getBalance());
    }

    public function testDeleteTransferDeletesTransactionsAndUpdatesAccountBalances(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        $expenseId = $fromExpense->getId();
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        $incomeId = $toIncome->getId();
        self::assertEquals($eurBalanceBefore - 100, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, (float) $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());

        $this->entityManager()->remove($transfer);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        $fromExpenseReloaded = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncomeReloaded = $this->entityManager()->getRepository(Income::class)->find($incomeId);
        self::assertNull($transfer);
        self::assertNull($fromExpenseReloaded);
        self::assertNull($toIncomeReloaded);
        self::assertEquals($eurBalanceBefore, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, (float) $this->accountCashUAH->getBalance());
    }

    public function testCreateTransferWithFee(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
                'fee' => '10.0',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        self::assertEquals(100.0, $fromExpense->getAmount());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());
        self::assertEquals($eurBalanceBefore - 110, (float) $fromExpense->getAccount()->getBalance());
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        self::assertEquals(100.0, $toIncome->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $toIncome->getAccount()->getId());
        self::assertEquals($uahBalanceBefore + 100, (float) $toIncome->getAccount()->getBalance());
        self::assertEquals(10, $transfer->getFee());
        $feeAccount = $transfer->getFeeAccount();
        \assert(null !== $feeAccount);
        self::assertEquals($this->accountCashEUR->getId(), $feeAccount->getId());
        $feeExpense = $transfer->getFeeExpense();
        \assert($feeExpense instanceof Expense);
        self::assertEquals('Transfer Fee', $feeExpense->getCategory()->getName());
    }

    public function testDeleteTransferFee(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '1',
                'fee' => '10.0',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        $expenseId = $fromExpense->getId();
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        $incomeId = $toIncome->getId();
        $feeExpense = $transfer->getFeeExpense();
        \assert($feeExpense instanceof Expense);
        $feeId = $feeExpense->getId();

        self::assertEquals($eurBalanceBefore - 110, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, (float) $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());

        $this->entityManager()->remove($feeExpense);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $fromExpense = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->entityManager()->getRepository(Income::class)->find($incomeId);
        $feeExpense = $this->entityManager()->getRepository(Expense::class)->find($feeId);

        self::assertNull($fromExpense);
        self::assertNull($toIncome);
        self::assertNull($feeExpense);
    }

    public function testDeleteTransferDeletesTransactionsAndUpdatesBalances(): void
    {
        $cashEurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $cashUahBalanceBefore = (float) $this->accountCashUAH->getBalance();

        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '10',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => '',
                'rate' => '40',
                'fee' => '1',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $transferId = $transfer->getId();
        $fromExpense = $transfer->getFromExpense();
        \assert($fromExpense instanceof Expense);
        $expenseId = $fromExpense->getId();
        $toIncome = $transfer->getToIncome();
        \assert($toIncome instanceof Income);
        $incomeId = $toIncome->getId();
        $feeExpense = $transfer->getFeeExpense();
        \assert($feeExpense instanceof Expense);
        $feeId = $feeExpense->getId();

        self::assertEquals($cashEurBalanceBefore - 10 - 1, (float) $this->accountCashEUR->getBalance());
        self::assertEquals($cashUahBalanceBefore + (10 * 40), (float) $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $fromExpense->getAccount()->getId());

        $this->entityManager()->remove($transfer);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        self::assertNull($transfer);

        $fromExpense = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        self::assertNull($fromExpense);

        $toIncome = $this->entityManager()->getRepository(Income::class)->find($incomeId);
        self::assertNull($toIncome);

        $feeExpense = $this->entityManager()->getRepository(Expense::class)->find($feeId);
        self::assertNull($feeExpense);
    }

    public function testUpdateTransfer(): void
    {
        $eurBalanceBefore = (float) $this->accountCashEUR->getBalance();
        $uahBalanceBefore = (float) $this->accountCashUAH->getBalance();

        $createResponse = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => $this->iri($this->accountCashEUR),
                'to' => $this->iri($this->accountCashUAH),
                'note' => 'Initial transfer',
                'rate' => '2',
                'fee' => '10.0',
                'feeAccount' => $this->iri($this->accountCashEUR),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $createdTransfer = $this->entityManager()->getRepository(Transfer::class)->find($createResponse->toArray()['id']);
        self::assertNotNull($createdTransfer);

        $fromExpenseId = $createdTransfer->getFromExpense()?->getId();
        $toIncomeId = $createdTransfer->getToIncome()?->getId();
        $feeExpenseId = $createdTransfer->getFeeExpense()?->getId();

        self::assertNotNull($fromExpenseId);
        self::assertNotNull($toIncomeId);
        self::assertNotNull($feeExpenseId);

        $eurAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $uahAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($eurAccount);
        self::assertNotNull($uahAccount);

        $this->client->request('PUT', '/api/transfers/' . $createdTransfer->getId(), [
            'json' => [
                'amount' => '50.0',
                'executedAt' => '2024-03-13T10:00:00Z',
                'from' => $this->iri($eurAccount),
                'to' => $this->iri($uahAccount),
                'note' => 'Updated transfer',
                'rate' => '3',
                'fee' => '5.0',
                'feeAccount' => $this->iri($uahAccount),
            ],
        ]);
        self::assertResponseIsSuccessful();

        $reloadedEurAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $reloadedUahAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($reloadedEurAccount);
        self::assertNotNull($reloadedUahAccount);

        $updatedTransfer = $this->entityManager()->getRepository(Transfer::class)->find($createdTransfer->getId());
        self::assertNotNull($updatedTransfer);
        self::assertEquals('Updated transfer', $updatedTransfer->getNote());
        self::assertEquals(50.0, $updatedTransfer->getAmount());
        self::assertEquals(3.0, $updatedTransfer->getRate());
        self::assertEquals(5.0, $updatedTransfer->getFee());

        $fromExpense = $updatedTransfer->getFromExpense();
        $toIncome = $updatedTransfer->getToIncome();
        $feeExpense = $updatedTransfer->getFeeExpense();

        self::assertNotNull($fromExpense);
        self::assertNotNull($toIncome);
        self::assertNotNull($feeExpense);

        // Existing transfer transactions must be updated in place, not recreated.
        self::assertEquals($fromExpenseId, $fromExpense->getId());
        self::assertEquals($toIncomeId, $toIncome->getId());
        self::assertEquals($feeExpenseId, $feeExpense->getId());

        self::assertEquals(50.0, $fromExpense->getAmount());
        self::assertEquals(150.0, $toIncome->getAmount());
        self::assertEquals(5.0, $feeExpense->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $feeExpense->getAccount()->getId());

        // Final effect after update should match updated transfer values.
        self::assertEquals($eurBalanceBefore - 50.0, (float) $reloadedEurAccount->getBalance());
        self::assertEquals($uahBalanceBefore + 145.0, (float) $reloadedUahAccount->getBalance());
    }
}
