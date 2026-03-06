<?php
namespace App\DataFixtures\Test;

use App\Entity\Transfer;
use App\EventListener\TransactionListener;
use App\EventListener\TransferCreateTransactionsHandler;
use App\EventListener\ValuableEntityEventListener;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TransferFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private TransactionListener $transactionListener,
        private ValuableEntityEventListener $valuableEntityListener,
        private TransferCreateTransactionsHandler $transferHandler
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->transactionListener->setEnabled(false);
        $this->valuableEntityListener->setEnabled(false);
        $this->transferHandler->setEnabled(false);

        $user = $this->getReference('test_user');
        $eurAccount = $this->getReference('account_eur_cash');
        $uahAccount = $this->getReference('account_uah_card');

        $transfer = new Transfer();
        $transfer->setFrom($eurAccount)
            ->setTo($uahAccount)
            ->setAmount(100)
            ->setRate(26)
            ->setFee(0)
            ->setOwner($user)
            ->setExecutedAt(CarbonImmutable::parse('2021-06-15'))
            ->setCreatedAt(CarbonImmutable::parse('2021-06-15'))
            ->setUpdatedAt(CarbonImmutable::parse('2021-06-15'));

        $manager->persist($transfer);
        $manager->flush();

        $this->transactionListener->setEnabled(true);
        $this->valuableEntityListener->setEnabled(true);
        $this->transferHandler->setEnabled(true);
    }

    public function getDependencies(): array
    {
        return [UserAndAccountFixtures::class, TransactionFixtures::class];
    }
}
