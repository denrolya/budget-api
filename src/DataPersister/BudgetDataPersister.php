<?php

declare(strict_types=1);

namespace App\DataPersister;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Budget;
use App\Entity\BudgetLine;
use App\Repository\BudgetRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

/** @implements ProcessorInterface<mixed, Budget> */
final class BudgetDataPersister implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetRepository $budgetRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Budget
    {
        if (!$data instanceof Budget) {
            throw new InvalidArgumentException('Expected Budget entity.');
        }

        $isCreate = !isset($context['previous_data']);

        if ($isCreate) {
            $this->copyLinesFromSource($data);
        }

        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }

    private function copyLinesFromSource(Budget $budget): void
    {
        $copiedFromId = $budget->getCopiedFromId();

        if (null === $copiedFromId) {
            return;
        }

        $sourceBudget = $this->budgetRepository->find($copiedFromId);

        if (null === $sourceBudget) {
            return;
        }

        foreach ($sourceBudget->getLines() as $sourceLine) {
            $line = new BudgetLine();
            $line->setCategory($sourceLine->getCategory());
            $line->setPlannedAmount($sourceLine->getPlannedAmount());
            $line->setPlannedCurrency($sourceLine->getPlannedCurrency());
            $budget->addLine($line);
        }
    }
}
