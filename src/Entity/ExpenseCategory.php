<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Repository\ExpenseCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: ExpenseCategoryRepository::class)]
#[ApiResource(
    operations: [
        new Post(uriTemplate: '/categories/expense', normalizationContext: ['groups' => 'category:write']),
    ],
    denormalizationContext: ['groups' => 'category:write'],
    paginationEnabled: false,
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

    public function getType(): string
    {
        return Category::EXPENSE_CATEGORY_TYPE;
    }
}
