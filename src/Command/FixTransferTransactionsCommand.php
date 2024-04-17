<?php

namespace App\Command;

use App\Entity\Transfer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fix:transfer-transactions',
    description: 'Add a short description for your command',
)]
class FixTransferTransactionsCommand extends Command
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->em = $entityManager;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $transfers = $this->em->getRepository(Transfer::class)->findBy([], ['id' => 'ASC']);
//        dump(count($transfers[0]->getTransactions()));
        $count = count($transfers);
        $io->info(sprintf('Found %d transfers', $count));
        foreach($transfers as $index => $transfer) {
            $io->info(sprintf('[%d/%d]: Processing transfer #%d', $index, $count, $transfer->getId()));
            $transfer->addTransaction($transfer->getFromExpense());
            $transfer->addTransaction($transfer->getToIncome());
            if($transfer->getFeeExpense()) {
                $transfer->addTransaction($transfer->getFeeExpense());
            }
            $io->info(sprintf('[%d/%d]: Transfer #%d processed', $index, $count, $transfer->getId()));

            $this->em->flush();
        }

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
