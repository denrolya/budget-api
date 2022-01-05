<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="App\Repository\ExpenseCategoryRepository")
 */
#[ApiResource(
    collectionOperations: [
        'get' => [
            'path' => '/categories/expense',
            'normalization_context' => ['groups' => 'category:collection:read'],
        ],
        'post' => [
            'path' => '/categories/expense',
            'normalization_context' => ['groups' => 'category:write'],
        ],
    ],
    itemOperations: [
        'get' => [
            'controller' => NotFoundAction::class,
            'read' => false,
            'output' => false,
        ],
    ],
    denormalizationContext: ['groups' => 'category:write']
)]
class ExpenseCategory extends Category
{
    public const CATEGORY_RENT = 'Rent';
    public const CATEGORY_UTILITIES = 'Utilities';
    public const CATEGORY_UTILITIES_GAS = 'Gas';
    public const CATEGORY_UTILITIES_WATER = 'Water utilities costs';
    public const CATEGORY_UTILITIES_ELECTRICITY = 'Electricity';
    public const CATEGORY_FOOD = 'Food & Drinks';
    public const CATEGORY_EATING_OUT = 'Eating Out';
    public const CATEGORY_GROCERIES = 'Groceries';
    public const CATEGORY_TAX = 'Tax';
    public const CATEGORY_SHOPPING = 'Shopping';

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false})
     */
    #[Groups(['category:collection:read', 'category:tree', 'category:write'])]
    private bool $isFixed = false;

    public function getIsFixed(): bool
    {
        return $this->isFixed;
    }

    public function setIsFixed(bool $isFixed): self
    {
        $this->isFixed = $isFixed;

        return $this;
    }

    public function getType(): string
    {
        return Category::EXPENSE_CATEGORY_TYPE;
    }
}
