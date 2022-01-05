<?php

namespace App\Entity;

use ApiPlatform\Core\Action\NotFoundAction;
use ApiPlatform\Core\Annotation\ApiResource;
use App\Repository\IncomeCategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass=IncomeCategoryRepository::class)
 */
#[ApiResource(
    collectionOperations: [
        'get' => [
            'path' => '/categories/income',
            'normalization_context' => ['groups' => 'category:collection:read'],
        ],
        'post' => [
            'path' => '/categories/income',
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
    denormalizationContext: ['groups' => 'category:write'],
)]
class IncomeCategory extends Category
{

    public function getType(): string
    {
        return Category::INCOME_CATEGORY_TYPE;
    }
}
