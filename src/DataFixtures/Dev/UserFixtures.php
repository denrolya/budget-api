<?php

declare(strict_types=1);

namespace App\DataFixtures\Dev;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setUsername('test_user')
            ->setPassword($this->passwordHasher->hashPassword($user, 'password123'))
            ->setRoles(['ROLE_USER']);

        $manager->persist($user);
        $manager->flush();
        $this->addReference('dev_user', $user);
    }
}
