<?php

namespace App\Command;

use App\Service\FixerService;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:convert:value',
    description: 'Converts given value using Fixer.io API',
)]
class ConvertValueCommand extends Command
{
    // inject FixerService here
    public function __construct(private readonly FixerService $fixerService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'Value to convert')
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Currency to convert from')
        ;
    }

    /**
     * @throws \JsonException
     * @throws InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $value = $input->getOption('value');
        $currency = $input->getOption('currency');

        if (!$value || !$currency) {
            $io->error('Value and currency are required!');

            return Command::FAILURE;
        }

        $convertedValue = $this->fixerService->convert($value, $currency);

        $io->writeln(json_encode($convertedValue, JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
