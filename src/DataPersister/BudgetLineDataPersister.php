<?php

declare(strict_types=1);

namespace App\DataPersister;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\BudgetLine;
use App\Entity\Category;
use App\Repository\BudgetRepository;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<mixed, BudgetLine|null> */
final class BudgetLineDataPersister implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BudgetRepository $budgetRepository,
        private readonly CategoryRepository $categoryRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BudgetLine
    {
        if (!$data instanceof BudgetLine) {
            throw new InvalidArgumentException('Expected BudgetLine entity.');
        }

        $budgetIdRaw = $uriVariables['budgetId'] ?? 0;
        \assert(is_numeric($budgetIdRaw));
        $budgetId = (int) $budgetIdRaw;
        $budget = $this->budgetRepository->find($budgetId);

        if (null === $budget) {
            throw new NotFoundHttpException("Budget $budgetId not found.");
        }

        if ($operation instanceof Post) {
            return $this->handleCreate($data, $budget);
        }

        $this->validateLineOwnership($data, $budgetId);

        if ($operation instanceof Delete) {
            return $this->handleDelete($data);
        }

        return $this->handleUpdate($data);
    }

    private function handleCreate(BudgetLine $line, \App\Entity\Budget $budget): BudgetLine
    {
        $categoryId = $line->getCategoryId();
        $category = null !== $categoryId ? $this->categoryRepository->find($categoryId) : null;

        if (!$category instanceof Category) {
            throw new UnprocessableEntityHttpException("Category with id $categoryId not found.");
        }

        $line->setCategory($category);
        $this->normalizeNote($line);
        $budget->addLine($line);
        $this->entityManager->persist($line);
        $this->entityManager->flush();

        return $line;
    }

    private function handleUpdate(BudgetLine $line): BudgetLine
    {
        $this->normalizeNote($line);
        $this->entityManager->flush();

        return $line;
    }

    private function handleDelete(BudgetLine $line): null
    {
        $this->entityManager->remove($line);
        $this->entityManager->flush();

        return null;
    }

    private function validateLineOwnership(BudgetLine $line, int $budgetId): void
    {
        if ($line->getBudget()->getId() !== $budgetId) {
            throw new NotFoundHttpException("Budget line {$line->getId()} not found in budget $budgetId.");
        }
    }

    /**
     * Normalizes empty string notes to null for consistency.
     */
    private function normalizeNote(BudgetLine $line): void
    {
        $note = $line->getNote();
        if (null !== $note && '' === trim($note)) {
            $line->setNote(null);
        }
    }
}
