<?php

declare(strict_types=1);

namespace App\DataFixtures\Test;

use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\Transfer;
use App\Entity\User;
use App\EventListener\DebtConvertedValueListener;
use App\EventListener\TransactionListener;
use App\Service\TransferService;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TransferFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TransactionListener $transactionListener,
        private DebtConvertedValueListener $valuableEntityListener,
        private TransferService $transferService,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->transactionListener->setEnabled(false);
        $this->valuableEntityListener->setEnabled(false);

        $user = $this->getReference('test_user', User::class);
        $eurAccount = $this->getReference('account_eur_cash', CashAccount::class);
        $uahAccount = $this->getReference('account_uah_card', BankCardAccount::class);

        // Transfer without fees
        $transfer = (new Transfer())
            ->setFrom($eurAccount)
            ->setTo($uahAccount)
            ->setAmount(100)
            ->setRate(26)
            ->setOwner($user)
            ->setExecutedAt(CarbonImmutable::parse('2021-06-15'))
            ->setCreatedAt(CarbonImmutable::parse('2021-06-15'))
            ->setUpdatedAt(CarbonImmutable::parse('2021-06-15'));

        $this->transferService->createTransactions($transfer);
        $manager->persist($transfer);

        // Transfer with single fee
        $transferWithFee = (new Transfer())
            ->setFrom($eurAccount)
            ->setTo($uahAccount)
            ->setAmount(200)
            ->setRate(26)
            ->setOwner($user)
            ->setExecutedAt(CarbonImmutable::parse('2021-07-10'))
            ->setCreatedAt(CarbonImmutable::parse('2021-07-10'))
            ->setUpdatedAt(CarbonImmutable::parse('2021-07-10'));

        $this->transferService->createTransactions($transferWithFee, [
            ['amount' => '5', 'account' => $eurAccount],
        ]);
        $manager->persist($transferWithFee);

        $manager->flush();

        $this->transactionListener->setEnabled(true);
        $this->valuableEntityListener->setEnabled(true);
    }

    public function getDependencies(): array
    {
        return [UserAndAccountFixtures::class, TransactionFixtures::class];
    }
}
