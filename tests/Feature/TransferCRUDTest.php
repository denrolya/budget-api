<?php

namespace App\Tests\Feature;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Entity\User;
use App\Tests\BaseApiTest;
use Carbon\CarbonInterface;

class TransferCRUDTest extends BaseApiTest
{
    private Account $accountMonoUAH;

    private Account $accountCashUAH;

    private Account $accountCashEUR;

    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountCashUAH = $this->em->getRepository(Account::class)->find(4);
        $this->accountCashEUR = $this->em->getRepository(Account::class)->find(2);
        $this->accountMonoUAH = $this->em->getRepository(Account::class)->find(10);
        $this->testUser = $this->em->getRepository(User::class)->find(2);
    }

    public function testCreateTransferCreatesTransactions(): void
    {
        $response = $this->client->request('POST', '/api/transfers', [
            'json' => [
                'amount' => '100.0',
                'executedAt' => '2024-03-12T09:35:00Z',
                'from' => (string)$this->accountMonoUAH->getId(),
                'to' => (string)$this->accountCashUAH->getId(),
                'note' => '',
                'rate' => '1',
            ],
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteTransferExpenseDeletesRelatedTransferWithTransactionsAndUpdatesAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    public function testDeleteTransferIncomeDeletesRelatedTransferWithTransactionsAndUpdatesAccountBalances(): void
    {
        self::markTestIncomplete('This test has not been implemented yet.');
    }

    private function createTransfer(
        float $amount,
        Account $from,
        Account $to,
        CarbonInterface $executedAt,
        string $note,
        float $rate,
        float $fee = null,
        Account $feeAccount = null,
    ): Transfer {
        return (new Transfer())
            ->setAmount($amount)
            ->setFrom($from)
            ->setTo($to)
            ->setExecutedAt($executedAt)
            ->setNote($note)
            ->setRate($rate)
            ->setFee($fee)
            ->setFeeAccount($feeAccount);
    }
}
