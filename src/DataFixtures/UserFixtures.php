<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    /**
     * @param UserPasswordHasherInterface $passwordHasher The password hasher service
     */
    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    /**
     * Load data fixtures with the passed ObjectManager.
     *
     * @param ObjectManager $manager The object manager to handle persistence
     */
    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user
            ->setUsername("test_user")
            ->setPassword($this->passwordHasher->hashPassword($user, 'password123'))
            ->setRoles(['ROLE_USER']);

        $manager->persist($user);
        $manager->flush();

        $this->addReference('test_user', $user);
    }
}
