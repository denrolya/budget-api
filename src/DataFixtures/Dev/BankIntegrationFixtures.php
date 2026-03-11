<?php

namespace App\DataFixtures\Dev;

use App\Bank\BankProvider;
use App\Bank\SyncMethod;
use App\Entity\BankIntegration;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class BankIntegrationFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        /** @var User $user */
        $user = $this->getReference('dev_user', User::class);

        $monobank = new BankIntegration();
        $monobank->setOwner($user)
            ->setProvider(BankProvider::Monobank)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setIsActive(true);
        $manager->persist($monobank);
        $this->addReference('dev_bank_integration_monobank', $monobank);

        $wise = new BankIntegration();
        $wise->setOwner($user)
            ->setProvider(BankProvider::Wise)
            ->setSyncMethod(SyncMethod::Webhook)
            ->setIsActive(true);
        $manager->persist($wise);
        $this->addReference('dev_bank_integration_wise', $wise);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
