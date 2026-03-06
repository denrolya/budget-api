<?php
namespace App\DataFixtures\Test;

use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\User;
use Carbon\CarbonImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserAndAccountFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setUsername('test_user')
            ->setPassword($this->passwordHasher->hashPassword($user, 'password123'))
            ->setRoles(['ROLE_USER']);
        $manager->persist($user);

        $eurCash = new CashAccount();
        $eurCash->setName('EUR Cash')
            ->setCurrency('EUR')
            ->setBalance('10000')
            ->setOwner($user)
            ->setCreatedAt(CarbonImmutable::parse('2019-01-01'))
            ->setUpdatedAt(CarbonImmutable::parse('2019-01-01'));
        $manager->persist($eurCash);

        $uahCard = new BankCardAccount();
        $uahCard->setName('UAH Card')
            ->setCurrency('UAH')
            ->setBalance('100000')
            ->setBankName('Test Bank')
            ->setCardNumber('4111111111111111')
            ->setIban('UA123456789012345678901234567')
            ->setOwner($user)
            ->setCreatedAt(CarbonImmutable::parse('2019-01-01'))
            ->setUpdatedAt(CarbonImmutable::parse('2019-01-01'));
        $manager->persist($uahCard);

        $manager->flush();

        $this->addReference('test_user', $user);
        $this->addReference('account_eur_cash', $eurCash);
        $this->addReference('account_uah_card', $uahCard);
    }
}
