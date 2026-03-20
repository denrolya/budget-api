<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\DemoSeedService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Seeds a user account with realistic demo data for testing and feedback collection.
 * Creates the user if they do not exist yet (default password: password123).
 *
 * Usage:
 *   bin/console app:demo:seed user@example.com            — create user if needed + seed
 *   bin/console app:demo:seed user@example.com --reset    — wipe all user data and re-seed
 *   bin/console app:demo:seed user@example.com --remove   — wipe all user data only
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed a user account with realistic demo data. Creates the user if needed.')]
class DemoSeedCommand extends Command
{
    private const DEFAULT_PASSWORD = 'password123';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly DemoSeedService $demoSeedService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Username / email of the target user')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Remove all existing user data then re-seed')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove all existing user data without re-seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        assert(is_string($email));

        $user = $this->userRepository->findOneBy(['username' => $email]);

        if (null === $user) {
            $user = $this->createUser($email);
            $io->info(sprintf('Created new user "%s" with password "%s".', $email, self::DEFAULT_PASSWORD));
        } else {
            $io->text(sprintf('Found existing user "%s".', $email));
        }

        $shouldRemove = $input->getOption('remove') || $input->getOption('reset');
        $shouldSeed = !$input->getOption('remove');

        if ($shouldRemove) {
            $confirmed = $io->confirm(
                sprintf('This will permanently delete ALL data for "%s". Continue?', $email),
                false,
            );

            if (!$confirmed) {
                $io->warning('Aborted.');

                return Command::SUCCESS;
            }

            $io->section('Removing existing data…');
            $this->demoSeedService->removeDataForUser($user);
            $io->success('All data removed.');
        }

        if (!$shouldSeed) {
            return Command::SUCCESS;
        }

        $io->section(sprintf('Seeding demo data for "%s"…', $email));
        $this->demoSeedService->seedForUser($user, $io);
        $io->success(sprintf('Done! Log in as "%s" with password "%s".', $email, self::DEFAULT_PASSWORD));

        return Command::SUCCESS;
    }

    private function createUser(string $username): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::DEFAULT_PASSWORD));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
