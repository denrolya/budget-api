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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
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
        $this->em->refresh($this->accountCashEUR);
        $this->em->refresh($this->accountCashUAH);

        $transfer = $this->em->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->em->getRepository(Expense::class)->findOneById($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->findOneById($incomeId);
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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
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
        $fromExpense = $this->em->getRepository(Expense::class)->findOneById($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->findOneById($incomeId);
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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
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
        $fromExpense = $this->em->getRepository(Expense::class)->findOneById($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->findOneById($incomeId);
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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
                'note' => '',
                'rate' => '1',
                'fee' => '10.0',
                'feeAccount' => (string)$this->accountCashEUR->getId(),
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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
                'note' => '',
                'rate' => '1',
                'fee' => '10.0',
                'feeAccount' => (string)$this->accountCashEUR->getId(),
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

        $fromExpense = $this->em->getRepository(Expense::class)->findOneById($expenseId);
        $toIncome = $this->em->getRepository(Income::class)->findOneById($incomeId);
        $feeExpense = $this->em->getRepository(Expense::class)->findOneById($feeId);

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
                'from' => (string)$this->accountCashEUR->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
                'note' => '',
                'rate' => '40',
                'fee' => '1',
                'feeAccount' => (string)$this->accountCashEUR->getId(),
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

        $fromExpense = $this->em->getRepository(Expense::class)->findOneById($expenseId);
        self::assertNull($fromExpense);

        $toIncome = $this->em->getRepository(Income::class)->findOneById($incomeId);
        self::assertNull($toIncome);

        $feeExpense = $this->em->getRepository(Expense::class)->findOneById($feeId);
        self::assertNull($feeExpense);
    }

    public function testUpdateTransfer(): void
    {
        self::markTestIncomplete('This functionality has not been implemented yet.');
    }
}
