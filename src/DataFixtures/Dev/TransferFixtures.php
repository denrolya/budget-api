<?php
namespace App\DataFixtures\Dev;

use App\Entity\Account;
use App\Entity\Transfer;
use Carbon\CarbonImmutable;
use Doctrine\Persistence\ObjectManager;

class TransferFixtures extends BaseTransactionFixtures
{
    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference('dev_user');

        $this->disableListeners();

        $accounts = $manager->getRepository(Account::class)->findAll();
        if (count($accounts) < 2) {
            $this->enableListeners();
            return;
        }

        $fromAccount = $accounts[0];
        $toAccount = $accounts[1];

        $transfer = new Transfer();
        $transfer->setFrom($fromAccount)
            ->setTo($toAccount)
            ->setAmount(500)
            ->setRate(1)
            ->setFee(0)
            ->setOwner($user)
            ->setExecutedAt(CarbonImmutable::now()->subWeek())
            ->setCreatedAt(CarbonImmutable::now()->subWeek())
            ->setUpdatedAt(CarbonImmutable::now());

        $manager->persist($transfer);
        $manager->flush();

        $this->enableListeners();
    }
}
