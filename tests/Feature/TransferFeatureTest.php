<?php

namespace App\Tests\Feature;

use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Transfer;
use App\Tests\BaseApiTestCase;

class TransferFeatureTest extends BaseApiTestCase
{
    public function testCreateTransferCreatesTransactions(): void
    {
        $eurBalanceBefore = $this->accountCashEUR->getBalance();
        $uahBalanceBefore = $this->accountCashUAH->getBalance();
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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        self::assertNotNull($transfer->getFromExpense());
        self::assertEquals(100.0, $transfer->getFromExpense()->getAmount());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());
        self::assertEquals($eurBalanceBefore - 100, $transfer->getFromExpense()->getAccount()->getBalance());
        self::assertNotNull($transfer->getToIncome());
        self::assertEquals(200.0, $transfer->getToIncome()->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $transfer->getToIncome()->getAccount()->getId());
        self::assertEquals($uahBalanceBefore + 200, $transfer->getToIncome()->getAccount()->getBalance());
    }

    public function testDeleteTransferExpenseDoesNotDeletesRelatedTransferAndUpdatesAccountBalances(): void
    {
        $eurBalanceBefore = $this->accountCashEUR->getBalance();
        $uahBalanceBefore = $this->accountCashUAH->getBalance();
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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        self::assertEquals($eurBalanceBefore - 100, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->em->remove($transfer->getFromExpense());
        $this->em->flush();
        $reloadedEurAccount = $this->em->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $reloadedUahAccount = $this->em->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($reloadedEurAccount);
        self::assertNotNull($reloadedUahAccount);

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->em->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->find($incomeId);
        self::assertNull($transfer);
        self::assertNull($fromExpense);
        self::assertNull($toIncome);
        self::assertEquals($eurBalanceBefore, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, $this->accountCashUAH->getBalance());
    }

    public function testDeleteTransferIncomesDoesNotDeletesRelatedTransferAndUpdatesAccountBalances(): void
    {
        $eurBalanceBefore = $this->accountCashEUR->getBalance();
        $uahBalanceBefore = $this->accountCashUAH->getBalance();
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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        self::assertEquals($eurBalanceBefore - 100, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->em->remove($transfer->getToIncome());
        $this->em->flush();
        $this->em->refresh($this->accountCashEUR);
        $this->em->refresh($this->accountCashUAH);

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->em->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->find($incomeId);
        self::assertNull($transfer);
        self::assertNull($fromExpense);
        self::assertNull($toIncome);
        self::assertEquals($eurBalanceBefore, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, $this->accountCashUAH->getBalance());
    }

    public function testDeleteTransferDeletesTransactionsAndUpdatesAccountBalances(): void
    {
        $eurBalanceBefore = $this->accountCashEUR->getBalance();
        $uahBalanceBefore = $this->accountCashUAH->getBalance();
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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        self::assertEquals($eurBalanceBefore - 100, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->em->remove($transfer);
        $this->em->flush();
        $this->em->refresh($this->accountCashEUR);
        $this->em->refresh($this->accountCashUAH);

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->em->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->find($incomeId);
        self::assertNull($transfer);
        self::assertNull($fromExpense);
        self::assertNull($toIncome);
        self::assertEquals($eurBalanceBefore, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, $this->accountCashUAH->getBalance());
    }

    public function testCreateTransferWithFee(): void
    {
        $eurBalanceBefore = $this->accountCashEUR->getBalance();
        $uahBalanceBefore = $this->accountCashUAH->getBalance();
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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        self::assertNotNull($transfer->getFromExpense());
        self::assertEquals(100.0, $transfer->getFromExpense()->getAmount());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());
        self::assertEquals($eurBalanceBefore - 110, $transfer->getFromExpense()->getAccount()->getBalance());
        self::assertNotNull($transfer->getToIncome());
        self::assertEquals(100.0, $transfer->getToIncome()->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $transfer->getToIncome()->getAccount()->getId());
        self::assertEquals($uahBalanceBefore + 100, $transfer->getToIncome()->getAccount()->getBalance());
        self::assertEquals(10, $transfer->getFee());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFeeAccount()->getId());
        self::assertEquals('Transfer Fee', $transfer->getFeeExpense()->getCategory()->getName());
    }

    public function testDeleteTransferFee(): void
    {
        $eurBalanceBefore = $this->accountCashEUR->getBalance();
        $uahBalanceBefore = $this->accountCashUAH->getBalance();
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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        $feeId = $transfer->getFeeExpense()->getId();

        self::assertEquals($eurBalanceBefore - 110, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->em->remove($transfer->getFeeExpense());
        $this->em->flush();
        $this->em->refresh($this->accountCashEUR);
        $this->em->refresh($this->accountCashUAH);

        $fromExpense = $this->em->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->find($incomeId);
        $feeExpense = $this->em->getRepository(Expense::class)->find($feeId);

        self::assertNull($fromExpense);
        self::assertNull($toIncome);
        self::assertNull($feeExpense);
    }

    public function testDeleteTransferDeletesTransactionsAndUpdatesBalances(): void
    {
        $cashEurBalanceBefore = $this->accountCashEUR->getBalance();
        $cashUahBalanceBefore = $this->accountCashUAH->getBalance();

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

        $transfer = $this->em->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        $feeId = $transfer->getFeeExpense()->getId();

        self::assertEquals($cashEurBalanceBefore - 10 - 1, $this->accountCashEUR->getBalance());
        self::assertEquals($cashUahBalanceBefore + (10 * 40), $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->em->remove($transfer);
        $this->em->flush();
        $this->em->refresh($this->accountCashEUR);
        $this->em->refresh($this->accountCashUAH);

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        self::assertNull($transfer);

        $fromExpense = $this->em->getRepository(Expense::class)->find($expenseId);
        self::assertNull($fromExpense);

        $toIncome = $this->em->getRepository(Income::class)->find($incomeId);
        self::assertNull($toIncome);

        $feeExpense = $this->em->getRepository(Expense::class)->find($feeId);
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

        $createdTransfer = $this->em->getRepository(Transfer::class)->find($createResponse->toArray()['id']);
        self::assertNotNull($createdTransfer);

        $fromExpenseId = $createdTransfer->getFromExpense()?->getId();
        $toIncomeId = $createdTransfer->getToIncome()?->getId();
        $feeExpenseId = $createdTransfer->getFeeExpense()?->getId();

        self::assertNotNull($fromExpenseId);
        self::assertNotNull($toIncomeId);
        self::assertNotNull($feeExpenseId);

        $eurAccount = $this->em->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $uahAccount = $this->em->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($eurAccount);
        self::assertNotNull($uahAccount);

        $this->client->request('PUT', '/api/transfers/'.$createdTransfer->getId(), [
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

        $reloadedEurAccount = $this->em->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $reloadedUahAccount = $this->em->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($reloadedEurAccount);
        self::assertNotNull($reloadedUahAccount);

        $updatedTransfer = $this->em->getRepository(Transfer::class)->find($createdTransfer->getId());
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
