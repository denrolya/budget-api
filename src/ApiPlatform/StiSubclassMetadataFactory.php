<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use App\Entity\BankCardAccount;
use App\Entity\CashAccount;
use App\Entity\Expense;
use App\Entity\ExpenseCategory;
use App\Entity\Income;
use App\Entity\IncomeCategory;
use App\Entity\InternetAccount;

/**
 * Strips inherited item operations (Get, Put, Delete) from STI subclass metadata.
 *
 * API Platform 3 auto-generates item routes for each STI subclass at subtype-prefixed
 * URLs (e.g. GET /expenses/{id}) even when the subclass only declares a Post operation.
 * These ghost routes duplicate the parent's item routes and are undesirable.
 *
 * By stripping item operations from subclass metadata, we prevent AP3 from generating
 * those ghost routes while the parent class routes continue to handle item operations
 * polymorphically via Doctrine's STI loading.
 */
class StiSubclassMetadataFactory implements ResourceMetadataCollectionFactoryInterface
{
    /** @var array<class-string> */
    private const STI_SUBCLASSES = [
        Expense::class,
        Income::class,
        ExpenseCategory::class,
        IncomeCategory::class,
        CashAccount::class,
        InternetAccount::class,
        BankCardAccount::class,
    ];

    public function __construct(
        private readonly ResourceMetadataCollectionFactoryInterface $decorated
    ) {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->decorated->create($resourceClass);

        if (!in_array($resourceClass, self::STI_SUBCLASSES, true)) {
            return $collection;
        }

        $resources = [];
        foreach ($collection as $resource) {
            $filteredOperations = [];
            foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                if ($operation instanceof Get || $operation instanceof Put || $operation instanceof Delete) {
                    continue;
                }
                $filteredOperations[$operationName] = $operation;
            }
            $resources[] = $resource->withOperations(new Operations($filteredOperations));
        }

        return new ResourceMetadataCollection($resourceClass, $resources);
    }
}
