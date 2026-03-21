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

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
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

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        self::assertEquals($eurBalanceBefore - 100, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->entityManager()->remove($transfer->getFromExpense());
        $this->entityManager()->flush();
        $reloadedEurAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $reloadedUahAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
        self::assertNotNull($reloadedEurAccount);
        self::assertNotNull($reloadedUahAccount);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->entityManager()->getRepository(Income::class)->find($incomeId);
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

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        self::assertEquals($eurBalanceBefore - 100, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->entityManager()->remove($transfer->getToIncome());
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->entityManager()->getRepository(Income::class)->find($incomeId);
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

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        self::assertEquals($eurBalanceBefore - 100, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->entityManager()->remove($transfer);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($transferId);
        $fromExpense = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->entityManager()->getRepository(Income::class)->find($incomeId);
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
                'fees' => [
                    ['amount' => '10.0', 'account' => $this->accountCashEUR->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);
        self::assertNotNull($transfer->getFromExpense());
        self::assertEquals(100.0, $transfer->getFromExpense()->getAmount());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());
        self::assertEquals($eurBalanceBefore - 110, $transfer->getFromExpense()->getAccount()->getBalance());
        self::assertNotNull($transfer->getToIncome());
        self::assertEquals(100.0, $transfer->getToIncome()->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $transfer->getToIncome()->getAccount()->getId());
        self::assertEquals($uahBalanceBefore + 100, $transfer->getToIncome()->getAccount()->getBalance());

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(1, $feeExpenses);
        self::assertEquals(10.0, $feeExpenses[0]->getAmount());
        self::assertEquals($this->accountCashEUR->getId(), $feeExpenses[0]->getAccount()->getId());
        self::assertEquals('Transfer Fee', $feeExpenses[0]->getCategory()->getName());
    }

    public function testCreateTransferWithMultipleFees(): void
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
                'fees' => [
                    ['amount' => '10.0', 'account' => $this->accountCashEUR->getId()],
                    ['amount' => '5.0', 'account' => $this->accountCashUAH->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $feeExpenses = $transfer->getFeeExpenses();
        self::assertCount(2, $feeExpenses);

        // EUR account: -100 (transfer) - 10 (fee) = -110
        self::assertEquals($eurBalanceBefore - 110, $this->accountCashEUR->getBalance());
        // UAH account: +100 (transfer) - 5 (fee) = +95
        self::assertEquals($uahBalanceBefore + 95, $this->accountCashUAH->getBalance());
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
                'fees' => [
                    ['amount' => '10.0', 'account' => $this->accountCashEUR->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        $feeExpenses = $transfer->getFeeExpenses();
        $feeId = $feeExpenses[0]->getId();

        self::assertEquals($eurBalanceBefore - 110, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore + 100, $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

        $this->entityManager()->remove($feeExpenses[0]);
        $this->entityManager()->flush();
        $this->entityManager()->refresh($this->accountCashEUR);
        $this->entityManager()->refresh($this->accountCashUAH);

        $fromExpense = $this->entityManager()->getRepository(Expense::class)->find($expenseId);
        $toIncome = $this->entityManager()->getRepository(Income::class)->find($incomeId);
        $feeExpense = $this->entityManager()->getRepository(Expense::class)->find($feeId);

        // orphanRemoval cascades: all transactions and transfer deleted
        self::assertNull($fromExpense);
        self::assertNull($toIncome);
        self::assertNull($feeExpense);

        // Balances must be fully restored
        self::assertEquals($eurBalanceBefore, $this->accountCashEUR->getBalance());
        self::assertEquals($uahBalanceBefore, $this->accountCashUAH->getBalance());
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
                'fees' => [
                    ['amount' => '1', 'account' => $this->accountCashEUR->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $content = $response->toArray();

        $transfer = $this->entityManager()->getRepository(Transfer::class)->find($content['id']);
        self::assertNotNull($transfer);

        $transferId = $transfer->getId();
        $expenseId = $transfer->getFromExpense()->getId();
        $incomeId = $transfer->getToIncome()->getId();
        $feeExpenses = $transfer->getFeeExpenses();
        $feeId = $feeExpenses[0]->getId();

        self::assertEquals($cashEurBalanceBefore - 10 - 1, $this->accountCashEUR->getBalance());
        self::assertEquals($cashUahBalanceBefore + (10 * 40), $this->accountCashUAH->getBalance());
        self::assertEquals($this->accountCashEUR->getId(), $transfer->getFromExpense()->getAccount()->getId());

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
                'fees' => [
                    ['amount' => '10.0', 'account' => $this->accountCashEUR->getId()],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $createdTransfer = $this->entityManager()->getRepository(Transfer::class)->find($createResponse->toArray()['id']);
        self::assertNotNull($createdTransfer);

        $fromExpenseId = $createdTransfer->getFromExpense()?->getId();
        $toIncomeId = $createdTransfer->getToIncome()?->getId();

        self::assertNotNull($fromExpenseId);
        self::assertNotNull($toIncomeId);

        $eurAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashEUR->getId());
        $uahAccount = $this->entityManager()->getRepository(\App\Entity\Account::class)->find($this->accountCashUAH->getId());
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
                'fees' => [
                    ['amount' => '5.0', 'account' => $uahAccount->getId()],
                ],
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

        $fromExpense = $updatedTransfer->getFromExpense();
        $toIncome = $updatedTransfer->getToIncome();
        $feeExpenses = $updatedTransfer->getFeeExpenses();

        self::assertNotNull($fromExpense);
        self::assertNotNull($toIncome);
        self::assertCount(1, $feeExpenses);

        // Core transaction IDs must be preserved
        self::assertEquals($fromExpenseId, $fromExpense->getId());
        self::assertEquals($toIncomeId, $toIncome->getId());

        self::assertEquals(50.0, $fromExpense->getAmount());
        self::assertEquals(150.0, $toIncome->getAmount());
        self::assertEquals(5.0, $feeExpenses[0]->getAmount());
        self::assertEquals($this->accountCashUAH->getId(), $feeExpenses[0]->getAccount()->getId());

        // Final effect after update should match updated transfer values.
        self::assertEquals($eurBalanceBefore - 50.0, (float) $reloadedEurAccount->getBalance());
        self::assertEquals($uahBalanceBefore + 145.0, (float) $reloadedUahAccount->getBalance());
    }
}
